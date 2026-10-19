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

function createEnhancement(PDO $db, int $productId, int $mediaId): array
{
    cleanupEnhancements();
    requireProduct($db, $productId);
    $media = run($db, 'SELECT * FROM media WHERE id=? AND product_id=?', [$mediaId, $productId])->fetch();
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
        ($memoryLimit > 0 && memory_get_usage(true) + $pixels * 16 + 32 * 1024 * 1024 > $memoryLimit))
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
        // Gentle contrast and sharpening, never resizing or inventing product details.
        if (!imagefilter($image, IMG_FILTER_CONTRAST, -3) ||
            !imageconvolution($image, [[0, -0.1, 0], [-0.1, 1.4, -0.1], [0, -0.1, 0]], 1, 0))
            throw new RuntimeException('تعذر تطبيق تحسين الصورة');
        imagesavealpha($image, true);
        if (@file_put_contents($original, $bytes) === false || !@imagepng($image, $enhanced, 6))
            throw new RuntimeException('تعذر حفظ المعاينة');
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
    return ['ok'=>true, 'token'=>$token,
        'original_url'=>'?action=enhancement_preview&token=' . $token . '&original=1',
        'preview_url'=>'?action=enhancement_preview&token=' . $token];
}

function approveEnhancement(PDO $db, string $token): array
{
    $preview = enhancementPreview($token);
    $newPath = 'uploads/' . bin2hex(random_bytes(18)) . '.png';
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
        $updated = run($db, 'UPDATE media SET path=?,sha256=? WHERE id=? AND product_id=? AND path=? AND sha256=?',
            [$newPath, hash_file('sha256', $target), $preview['media_id'], $preview['product_id'], $preview['source_path'], $preview['db_sha']]);
        if ($updated->rowCount() !== 1) throw new RuntimeException('الصورة اتغيرت أو اتحذفت أثناء المعاينة. حدّث الصفحة وحاول مرة أخرى.');
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
