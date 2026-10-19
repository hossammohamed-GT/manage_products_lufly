<?php
declare(strict_types=1);

// Previews are private, session-bound, short-lived files outside the web root.
function enhancementDirectory(): string
{
    $directory = sys_get_temp_dir() . '/lufly-enhancements-' . substr(hash('sha256', dirname(__DIR__)), 0, 16);
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory))
        throw new RuntimeException('تعذر إنشاء ملفات المعاينة. راجع صلاحيات المجلد المؤقت.');
    return $directory;
}

function discardEnhancement(string $token): void
{
    if (!preg_match('/^[a-f0-9]{48}$/', $token) || !isset($_SESSION['enhancements'][$token])) return;
    $preview = $_SESSION['enhancements'][$token];
    @unlink($preview['original_file']);
    @unlink($preview['enhanced_file']);
    unset($_SESSION['enhancements'][$token]);
}

function cleanupEnhancements(): void
{
    foreach ($_SESSION['enhancements'] ?? [] as $token => $preview) {
        if ($preview['expires'] <= time()) discardEnhancement($token);
    }
    // Also collect abandoned previews whose browser/session never came back.
    $directory = enhancementDirectory();
    foreach (glob($directory . '/*') ?: [] as $file) {
        if (is_file($file) && filemtime($file) < time() - 600) @unlink($file);
    }
}

function enhancementSource(string $path): string
{
    $file = realpath(dirname(__DIR__) . '/' . ltrim(str_replace('\\', '/', $path), '/'));
    if ($file !== false && is_file($file)) {
        foreach (['uploads', 'assets/catalog'] as $directory) {
            $root = realpath(dirname(__DIR__) . '/' . $directory);
            if ($root !== false && str_starts_with($file, $root . DIRECTORY_SEPARATOR)) return $file;
        }
    }
    throw new RuntimeException('ملف الصورة غير موجود أو مساره غير مسموح.');
}

function enhancementPreview(string $token): array
{
    cleanupEnhancements();
    $preview = preg_match('/^[a-f0-9]{48}$/', $token) ? ($_SESSION['enhancements'][$token] ?? null) : null;
    if (!$preview || !is_file($preview['original_file']) || !is_file($preview['enhanced_file']))
        throw new RuntimeException('انتهت صلاحية المعاينة. اضغط تحسين الصورة مرة أخرى.');
    return $preview;
}

/** Stronger non-neural, luminance-only processing tuned for white catalog products. */
function enhanceWhitewareLocal(GdImage $image): void
{
    $width = imagesx($image); $height = imagesy($image);
    $fine = imagecreatetruecolor($width, $height);
    $small = imagecreatetruecolor(max(1, (int)ceil($width / 16)), max(1, (int)ceil($height / 16)));
    $broad = imagecreatetruecolor($width, $height);
    $tones = [];
    for ($i = 0; $i <= 255; $i++) $tones[$i] = -12 * pow(max(0, sin(M_PI * $i / 255)), 1.2);
    try {
        foreach ([$fine, $small, $broad] as $buffer) { imagealphablending($buffer, false); imagesavealpha($buffer, true); }
        imagecopy($fine, $image, 0, 0, 0, 0, $width, $height);
        imagefilter($fine, IMG_FILTER_GAUSSIAN_BLUR);
        imagecopyresampled($small, $image, 0, 0, 0, 0, imagesx($small), imagesy($small), $width, $height);
        imagecopyresampled($broad, $small, 0, 0, 0, 0, $width, $height, imagesx($small), imagesy($small));
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $pixel = imagecolorat($image, $x, $y);
                $alpha = ($pixel >> 24) & 127;
                if ($alpha === 127) continue;
                $r = ($pixel >> 16) & 255; $g = ($pixel >> 8) & 255; $b = $pixel & 255;
                $luma = .2126 * $r + .7152 * $g + .0722 * $b;
                if (min($r, $g, $b) >= 253) continue; // preserve clean white backgrounds
                $near = imagecolorat($fine, $x, $y); $far = imagecolorat($broad, $x, $y);
                $nearY = .2126 * (($near >> 16) & 255) + .7152 * (($near >> 8) & 255) + .0722 * ($near & 255);
                $farY = .2126 * (($far >> 16) & 255) + .7152 * (($far >> 8) & 255) + .0722 * ($far & 255);
                $detail = $luma - $nearY;
                // Noise threshold + bounded sharpening avoids glowing/dark outlines.
                $sharpen = abs($detail) > 1 ? max(-5, min(5, $detail)) : 0;
                $local = max(-10, min(10, $luma - $farY));
                $tone = $tones[(int)round($luma)];
                $delta = ($tone + .85 * $local + $sharpen) * max(0, min(1, (253 - $luma) / 18));
                $nr = max(0, min(255, (int)round($r + $delta)));
                $ng = max(0, min(255, (int)round($g + $delta)));
                $nb = max(0, min(255, (int)round($b + $delta)));
                imagesetpixel($image, $x, $y, ($alpha << 24) | ($nr << 16) | ($ng << 8) | $nb);
            }
        }
    } finally {
        imagedestroy($fine); imagedestroy($small); imagedestroy($broad);
    }
}

function enhancementAiConfigured(): bool
{
    global $config;
    $python = $config['enhancement_python'] ?? '';
    return is_string($python) && $python !== '' && is_file($python) && function_exists('proc_open');
}

/** Fixed worker + argument array: no shell interpolation and no user-supplied commands. */
function runEnhancementAi(GdImage $image, string $target): void
{
    global $config;
    if (!enhancementAiConfigured())
        throw new RuntimeException('مسار AI يحتاج إعداد Python ومكتبات التحسين على السيرفر. استخدم وضع المنتجات البيضاء أو راجع دليل الإعداد.');
    if (imagesx($image) * imagesy($image) > 1500000)
        throw new RuntimeException('AI ×2 متاح حتى 1.5 ميجابكسل للصورة الأصلية. استخدم التحسين المحلي للصور الأكبر.');
    $input = $target . '.input.png';
    $process = null; $pipes = [];
    try {
        if (!@imagepng($image, $input, 3)) throw new RuntimeException('تعذر تجهيز الصورة للنموذج');
        $command = [$config['enhancement_python'], dirname(__DIR__) . '/tools/image_enhancement/enhance.py', '--input', $input, '--output', $target];
        $process = @proc_open($command, [0=>['pipe','r'], 1=>['pipe','w'], 2=>['pipe','w']], $pipes, dirname(__DIR__), null, ['bypass_shell'=>true]);
        if (!is_resource($process)) throw new RuntimeException('تعذر تشغيل نموذج AI على السيرفر');
        fclose($pipes[0]); unset($pipes[0]);
        foreach ($pipes as $pipe) stream_set_blocking($pipe, false);
        $started = microtime(true); $status = null;
        do {
            // Drain both streams so a worker error can never deadlock on a full pipe.
            foreach ($pipes as $pipe) stream_get_contents($pipe);
            $status = proc_get_status($process);
            if (!$status['running']) break;
            if (microtime(true) - $started > 30) {
                proc_terminate($process, 9);
                throw new RuntimeException('تجاوز AI مهلة 30 ثانية. جرّب صورة أصغر أو التحسين المحلي.');
            }
            usleep(50000);
        } while (true);
        if ($status['exitcode'] !== 0 || !is_file($target))
            throw new RuntimeException('تعذر تشغيل AI. تأكد من تثبيت مكتبات Python ودعم OpenCV contrib كما في دليل الإعداد.');
        $info = @getimagesize($target);
        if (!$info || $info['mime'] !== 'image/png' || $info[0] !== imagesx($image) * 2 || $info[1] !== imagesy($image) * 2)
            throw new RuntimeException('النموذج لم ينتج صورة صحيحة بالأبعاد المطلوبة');
    } finally {
        if (is_resource($process)) { foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe); proc_close($process); }
        @unlink($input);
    }
}

function createEnhancement(PDO|JsonCatalogStore $db, int $productId, int $mediaId, string $profile = 'whiteware'): array
{
    if (!in_array($profile, ['whiteware', 'ai'], true)) throw new RuntimeException('وضع تحسين غير صالح');
    @set_time_limit(45);
    cleanupEnhancements();
    requireProduct($db, $productId);
    $media = catalogRow($db, 'media', ['id'=>$mediaId,'product_id'=>$productId]);
    if (!$media || !(int)$media['previewable']) throw new RuntimeException('الصورة غير موجودة');
    if (!extension_loaded('gd')) throw new RuntimeException('تحسين الصور يحتاج تفعيل مكتبة GD في PHP على السيرفر.');
    $source = enhancementSource($media['path']);
    $info = @getimagesize($source);
    if (!$info || !in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true))
        throw new RuntimeException('التحسين متاح للصور الثابتة JPG وPNG وWebP فقط.');
    $pixels = $info[0] * $info[1];
    // Bound both compressed input and decoded GD allocations before decoding.
    $limit = trim((string)ini_get('memory_limit'));
    $memoryLimit = (int)$limit;
    $unit = strtolower(substr($limit, -1));
    if (in_array($unit, ['k', 'm', 'g'], true)) $memoryLimit *= 1024 ** (strpos('kmg', $unit) + 1);
    if ($pixels > 6000000 || filesize($source) > 12 * 1024 * 1024 ||
        ($memoryLimit > 0 && memory_get_usage(true) + $pixels * 24 + 32 * 1024 * 1024 > $memoryLimit))
        throw new RuntimeException('الصورة كبيرة جدًا للتحسين الآمن على السيرفر (الحد الأقصى 6 ميجابكسل و12 ميجابايت).');
    $bytes = @file_get_contents($source);
    if ($bytes === false) throw new RuntimeException('تعذر قراءة الصورة');
    if ($info['mime'] === 'image/webp') {
        // Animated WebP must not silently become a single still frame.
        for ($offset = 12; $offset + 8 <= strlen($bytes);) {
            if (substr($bytes, $offset, 4) === 'ANIM') throw new RuntimeException('الصور المتحركة غير مدعومة في التحسين.');
            $size = unpack('V', substr($bytes, $offset + 4, 4))[1];
            $offset += 8 + $size + ($size % 2);
        }
    }
    $image = @imagecreatefromstring($bytes);
    if (!$image) throw new RuntimeException('تعذر معالجة الصورة. تأكد من دعم نوعها في مكتبة GD.');
    $token = bin2hex(random_bytes(24));
    $original = enhancementDirectory() . '/' . $token . '.original';
    $enhanced = enhancementDirectory() . '/' . $token . '.png';
    try {
        imagepalettetotruecolor($image);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        // Match browser orientation for camera JPEGs when EXIF support is available.
        if ($info['mime'] === 'image/jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data($source);
            $orientation = (int)($exif['Orientation'] ?? 1);
            if (in_array($orientation, [2, 4, 5, 7], true)) imageflip($image, IMG_FLIP_HORIZONTAL);
            $angle = [3=>180, 4=>180, 5=>90, 6=>-90, 7=>-90, 8=>90][$orientation] ?? 0;
            if ($angle) {
                $rotated = imagerotate($image, $angle, 0);
                if (!$rotated) throw new RuntimeException('تعذر ضبط اتجاه الصورة');
                imagedestroy($image);
                $image = $rotated;
            }
        }
        $sourceWidth = imagesx($image); $sourceHeight = imagesy($image);
        if ($profile === 'ai') {
            runEnhancementAi($image, $enhanced);
        } else {
            enhanceWhitewareLocal($image);
            imagesavealpha($image, true);
            if (!@imagepng($image, $enhanced, 6)) throw new RuntimeException('تعذر حفظ المعاينة');
        }
        if (@file_put_contents($original, $bytes) === false) throw new RuntimeException('تعذر حفظ الصورة الأصلية للمعاينة');
        @chmod($original, 0600);
        @chmod($enhanced, 0600);
    } catch (Throwable $e) {
        @unlink($original);
        @unlink($enhanced);
        throw $e;
    } finally {
        imagedestroy($image);
    }
    // Keep at most three previews in this session (multiple tabs are supported).
    while (count($_SESSION['enhancements'] ?? []) >= 3) discardEnhancement(array_key_first($_SESSION['enhancements']));
    $_SESSION['enhancements'][$token] = [
        'product_id'=>$productId, 'media_id'=>$mediaId, 'source_path'=>$media['path'],
        'db_sha'=>$media['sha256'], 'source_sha'=>hash('sha256', $bytes),
        'original_file'=>$original, 'enhanced_file'=>$enhanced, 'mime'=>$info['mime'], 'expires'=>time() + 600,
    ];
    $output = getimagesize($enhanced);
    return ['ok'=>true, 'token'=>$token, 'profile'=>$profile,
        'engine'=>$profile === 'ai' ? 'FSRCNN ×2 + Whiteware' : 'Whiteware Local',
        'source_width'=>$sourceWidth, 'source_height'=>$sourceHeight, 'width'=>$output[0], 'height'=>$output[1],
        'scale'=>$profile === 'ai' ? 2 : 1,
        'original_url'=>'?action=enhancement_preview&token=' . $token . '&original=1',
        'preview_url'=>'?action=enhancement_preview&token=' . $token];
}

function approveEnhancement(PDO|JsonCatalogStore $db, string $token): array
{
    $preview = enhancementPreview($token);
    $newPath = catalogUploadDirectory() . bin2hex(random_bytes(18)) . '.png';
    $target = dirname(__DIR__) . '/' . $newPath;
    try {
        $db->beginTransaction();
        requireProduct($db, $preview['product_id']);
        $source = enhancementSource($preview['source_path']);
        $sourceHash = @hash_file('sha256', $source);
        if ($sourceHash === false || !hash_equals($preview['source_sha'], $sourceHash))
            throw new RuntimeException('الصورة الأصلية اتغيرت. حدّث الصفحة وابدأ تحسينًا جديدًا.');
        if (!is_dir(dirname($target)) && !@mkdir(dirname($target), 0755, true)) throw new RuntimeException('تعذر إنشاء مجلد الصور');
        if (!@copy($preview['enhanced_file'], $target)) throw new RuntimeException('تعذر حفظ الصورة المحسنة');
        $updated = catalogUpdate($db, 'media', ['path'=>$newPath,'sha256'=>hash_file('sha256', $target)],
            ['id'=>$preview['media_id'],'product_id'=>$preview['product_id'],'path'=>$preview['source_path'],'sha256'=>$preview['db_sha']]);
        if ($updated !== 1) throw new RuntimeException('الصورة اتغيرت أو اتحذفت أثناء المعاينة. حدّث الصفحة وحاول مرة أخرى.');
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        @unlink($target);
        throw $e;
    }
    discardEnhancement($token);
    deleteMediaFile($db, $preview['source_path']);
    return ['ok'=>true, 'op'=>'replace', 'media_id'=>$preview['media_id'], 'path'=>$newPath];
}
