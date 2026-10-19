<?php
declare(strict_types=1);
ini_set('session.use_strict_mode', '1');
ini_set('default_socket_timeout', '2');
set_time_limit(15);
ini_set('session.cookie_path', '/');
session_set_cookie_params([
    'lifetime' => 28800,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
$config = require __DIR__ . '/config.php';
if (is_file(__DIR__ . '/config.local.php'))
    $config = array_replace($config, require __DIR__ . '/config.local.php');

require_once __DIR__ . '/includes/catalog_storage.php';
$config['driver'] = catalogDriver($config, $_SERVER, PHP_SAPI);
// Hosted/database mode deliberately fails on connection errors; no silent data-store switch.
const STATUSES = ['Running', 'New Model', 'Slow Moving', 'Aging'];
function h($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function csrf(): string
{
    if (empty($_SESSION['csrf']))
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function token(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf()) . '">';
}
function checkCsrf(): void
{
    $submitted = (string)($_POST['csrf'] ?? '');
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $submitted))
        throw new RuntimeException('انتهت صلاحية الطلب. حدّث الصفحة وحاول مرة أخرى.');
}
function go(string $url): void
{
    header('Location: ' . $url);
    exit;
}
function flash(string $message): void
{
    $_SESSION['flash'] = $message;
}
function groupCode(string $code): string
{
    $code = trim($code);
    $pos = strpos($code, '-');
    return $pos !== false && $pos > 0 ? strtoupper(trim(substr($code, 0, $pos))) : 'غير مصنف';
}
function db(bool $serverOnly = false): PDO|JsonCatalogStore
{
    global $config;
    if ($config['driver'] === 'json') {
        static $stores = [];
        $path = $config['json_path'];
        return $stores[$path] ??= new JsonCatalogStore($path, __DIR__ . '/data/catalog.json');
    }
    if ($config['driver'] === 'sqlite') {
        $pdo = new PDO('sqlite:' . $config['sqlite_path']);
        $pdo->exec('PRAGMA foreign_keys=ON');
        $pdo->exec('PRAGMA busy_timeout=5000');
    } else {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $config['database']))
            throw new RuntimeException('اسم قاعدة البيانات غير صالح.');
        $dsn = 'mysql:host=' . $config['host'] . ';port=' . (int) $config['port'] . ($serverOnly ? '' : ';dbname=' . $config['database']) . ';charset=utf8mb4';
        $pdo = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_TIMEOUT => 3,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    return $pdo;
}
function run(PDO $db, string $sql, array $params = []): PDOStatement
{
    $s = $db->prepare($sql);
    $s->execute($params);
    return $s;
}
function setting(PDO|JsonCatalogStore $db, string $key, string $default = ''): string
{
    try {
        $row = catalogRow($db, 'settings', ['name'=>$key]);
        return $row === false ? $default : $row['value'];
    } catch (Throwable $e) {
        return $default;
    }
}
function setSetting(PDO|JsonCatalogStore $db, string $key, string $value): void
{
    if (catalogRow($db, 'settings', ['name'=>$key]) === false)
        catalogInsert($db, 'settings', ['name'=>$key, 'value'=>$value]);
    else catalogUpdate($db, 'settings', ['value'=>$value], ['name'=>$key]);
}
function install(): void
{
    global $config;
    $db = db(true);
    if ($db instanceof JsonCatalogStore) return;
    if ($config['driver'] === 'mysql') {
        $db->exec('CREATE DATABASE IF NOT EXISTS `' . $config['database'] . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $db = db();
        $sql = file_get_contents(__DIR__ . '/database/schema.sql');
        foreach (explode(';', $sql) as $part)
            if (trim($part) !== '' && !preg_match('/^\s*(CREATE DATABASE|USE )/i', $part))
                $db->exec($part);
        // Ensure verified column exists (for existing databases)
        try {
            $db->exec('ALTER TABLE products ADD COLUMN verified INT NOT NULL DEFAULT 0');
        } catch (Throwable $e) {
        }
        try {
            $db->exec('ALTER TABLE products ADD INDEX idx_verified (verified)');
        } catch (Throwable $e) {
        }
    } else {
        // Ensure SQLite directory exists
        $sqliteDir = dirname($config['sqlite_path']);
        if (!is_dir($sqliteDir))
            mkdir($sqliteDir, 0755, true);
        $db->exec('CREATE TABLE IF NOT EXISTS products (id INTEGER PRIMARY KEY AUTOINCREMENT,code TEXT NOT NULL DEFAULT "",brand TEXT NOT NULL DEFAULT "lufly",status TEXT NOT NULL DEFAULT "Running",description TEXT NOT NULL,notes TEXT NOT NULL,source_file TEXT NOT NULL DEFAULT "",source_row INTEGER NOT NULL DEFAULT 0,source_extra TEXT NOT NULL,archived INTEGER NOT NULL DEFAULT 0,verified INTEGER NOT NULL DEFAULT 0,version INTEGER NOT NULL DEFAULT 1,created_at TEXT NOT NULL,updated_at TEXT NOT NULL)');
        $db->exec('CREATE TABLE IF NOT EXISTS media (id INTEGER PRIMARY KEY AUTOINCREMENT,product_id INTEGER NOT NULL REFERENCES products(id) ON DELETE CASCADE,path TEXT NOT NULL,original_name TEXT NOT NULL,kind TEXT NOT NULL,caption TEXT NOT NULL DEFAULT "",sort_order INTEGER NOT NULL DEFAULT 0,previewable INTEGER NOT NULL DEFAULT 1,source_column TEXT NOT NULL DEFAULT "",source_row INTEGER NOT NULL DEFAULT 0,sha256 TEXT NOT NULL DEFAULT "")');
        $db->exec('CREATE TABLE IF NOT EXISTS settings (name TEXT PRIMARY KEY,value TEXT NOT NULL)');
    }
    $db->beginTransaction();
    try {
        if (setting($db, 'installed') === '1' || (int) $db->query('SELECT COUNT(*) FROM products')->fetchColumn() > 0)
            throw new RuntimeException('تم التثبيت بالفعل. لن تتم إعادة استيراد أو استبدال بياناتك.');
        $seed = json_decode(file_get_contents(__DIR__ . '/data/catalog.json'), true, 512, JSON_THROW_ON_ERROR);
        $now = date('c');
        foreach ($seed['products'] as $p)
            run($db, 'INSERT INTO products (id,code,brand,status,description,notes,source_file,source_row,source_extra,verified,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', [$p['id'], $p['code'], $p['brand'], $p['status'], $p['description'], $p['notes'], $p['source_file'], $p['source_row'], $p['source_extra'], 0, $now, $now]);
        foreach ($seed['media'] as $m)
            run($db, 'INSERT INTO media (id,product_id,path,original_name,kind,caption,sort_order,previewable,source_column,source_row,sha256) VALUES (?,?,?,?,?,?,?,?,?,?,?)', [$m['id'], $m['product_id'], $m['path'], $m['original_name'], $m['kind'], $m['caption'], $m['sort_order'], (int) $m['previewable'], $m['source_column'], $m['source_row'], $m['sha256']]);
        setSetting($db, 'installed', '1');
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction())
            $db->rollBack();
        throw $e;
    }
}
function mediaFor(PDO|JsonCatalogStore $db, int $id): array
{
    return catalogRows($db, 'media', ['product_id'=>$id], ['sort_order'=>'ASC','id'=>'ASC']);
}
function uploadFiles(string $field = 'files'): array
{
    if (empty($_FILES[$field]))
        return [];
    $f = $_FILES[$field];
    $result = [];
    $names = is_array($f['name']) ? $f['name'] : [$f['name']];
    if (count($names) > 30)
        throw new RuntimeException('الحد الأقصى 30 ملفًا في الطلب.');
    try {
        foreach ($names as $i => $name) {
            $get = fn($k) => is_array($f[$k]) ? $f[$k][$i] : $f[$k];
            $error = $get('error');
            if ($error === UPLOAD_ERR_NO_FILE)
                continue;
            if ($error !== UPLOAD_ERR_OK)
                throw new RuntimeException('تعذر رفع الملف. راجع upload_max_filesize وpost_max_size في PHP.');
            if ((int) $get('size') > 12 * 1024 * 1024)
                throw new RuntimeException('أقصى حجم للصورة 12 ميجابايت.');
            $tmp = $get('tmp_name');
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
            $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
            if (!isset($allowed[$mime]) || @getimagesize($tmp) === false)
                throw new RuntimeException('مسموح فقط بصور JPG وPNG وWebP وGIF صحيحة.');
            if (!is_dir(__DIR__ . '/uploads'))
                mkdir(__DIR__ . '/uploads', 0755, true);
            $directory = catalogUploadDirectory();
            $path = $directory . bin2hex(random_bytes(18)) . '.' . $allowed[$mime];
            if (!move_uploaded_file($tmp, __DIR__ . '/' . $path))
                throw new RuntimeException('تعذر حفظ الصورة. تأكد من صلاحيات مجلد uploads.');
            $result[] = ['path' => $path, 'original_name' => substr(basename(str_replace('\\', '/', (string) $name)), 0, 240), 'sha256' => hash_file('sha256', __DIR__ . '/' . $path)];
        }
    } catch (Throwable $e) {
        foreach ($result as $m)
            @unlink(__DIR__ . '/' . $m['path']);
        throw $e;
    }
    return $result;
}
function saveProduct(PDO|JsonCatalogStore $db): int
{
    $id = max(0, (int) ($_POST['id'] ?? 0));
    $code = trim((string) ($_POST['code'] ?? ''));
    $brand = trim((string) ($_POST['brand'] ?? ''));
    $status = (string) ($_POST['status'] ?? '');
    $description = trim((string) ($_POST['description'] ?? ''));
    $notes = trim((string) ($_POST['notes'] ?? ''));
    if (strlen($code) > 190 || strlen($brand) > 190 || strlen($description) > 60000 || strlen($notes) > 60000)
        throw new RuntimeException('بعض الحقول أطول من الحد المسموح.');
    if (!in_array($status, STATUSES, true))
        throw new RuntimeException('حالة المنتج غير صالحة.');
    if ($code === '' && $description === '')
        throw new RuntimeException('اكتب كود المنتج أو وصفه على الأقل.');
    $kind = (string) ($_POST['upload_kind'] ?? 'photo');
    if (!in_array($kind, ['photo', 'drawing', 'extra'], true))
        throw new RuntimeException('نوع المرفق غير صالح.');
    $files = uploadFiles();
    $removedPaths = [];
    try {
        $db->beginTransaction();
        $now = date('c');
        if ($id) {
            $changed = catalogUpdate($db, 'products', ['code'=>$code,'brand'=>$brand,'status'=>$status,'description'=>$description,'notes'=>$notes,'updated_at'=>$now],
                ['id'=>$id,'version'=>(int)($_POST['version'] ?? 0),'archived'=>0], true);
            if ($changed !== 1)
                throw new RuntimeException('تم تعديل المنتج في نافذة أخرى أو نقله للأرشيف. حدّث الصفحة قبل الحفظ حتى لا تفقد تعديلات أخرى.');
        } else {
            $id = catalogInsert($db, 'products', ['code'=>$code,'brand'=>$brand,'status'=>$status,'description'=>$description,'notes'=>$notes,'source_extra'=>'{}','created_at'=>$now,'updated_at'=>$now]);
        }
        foreach (mediaFor($db, $id) as $m) {
            $mid = $m['id'];
            if (isset($_POST['remove_media'][$mid])) {
                catalogDelete($db, 'media', ['id'=>$mid,'product_id'=>$id]);
                $removedPaths[] = $m['path'];
                continue;
            }
            $caption = (string) ($_POST['caption'][$mid] ?? $m['caption']);
            $type = (string) ($_POST['kind'][$mid] ?? $m['kind']);
            if (strlen($caption) > 255 || !in_array($type, ['photo', 'drawing', 'extra'], true))
                throw new RuntimeException('بيانات المرفق غير صالحة.');
            catalogUpdate($db, 'media', ['caption'=>$caption,'kind'=>$type,'sort_order'=>(int)($_POST['sort'][$mid] ?? $m['sort_order'])], ['id'=>$mid,'product_id'=>$id]);
        }
        foreach ($files as $i => $m)
            catalogInsert($db, 'media', ['product_id'=>$id,'path'=>$m['path'],'original_name'=>$m['original_name'],'kind'=>$kind,'caption'=>'','sort_order'=>10000+$i,'previewable'=>1,'source_column'=>'','source_row'=>0,'sha256'=>$m['sha256']]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction())
            $db->rollBack();
        foreach ($files as $m)
            @unlink(__DIR__ . '/' . $m['path']);
        throw $e;
    }
    foreach (array_unique($removedPaths) as $path) deleteMediaFile($db, $path);
    return $id;
}
function allProducts(PDO|JsonCatalogStore $db, bool $archived = false): array
{
    $rows = catalogRows($db, 'products', ['archived'=>(int)$archived], ['id'=>'ASC']);
    $media = [];
    foreach (catalogRows($db, 'media', [], ['sort_order'=>'ASC','id'=>'ASC']) as $m) $media[$m['product_id']][] = $m;
    foreach ($rows as &$product) {
        $attachments = $media[$product['id']] ?? [];
        $product['media_count'] = count($attachments);
        $previews = array_values(array_filter($attachments, fn($m) => (int)$m['previewable']));
        $photos = array_values(array_filter($previews, fn($m) => $m['kind'] === 'photo'));
        $product['cover'] = ($photos[0] ?? $previews[0] ?? [])['path'] ?? null;
    }
    unset($product);
    $counts = [];
    foreach ($rows as $p) {
        $key = strtoupper(trim($p['code']));
        if ($key !== '')
            $counts[$key] = ($counts[$key] ?? 0) + 1;
    }
    foreach ($rows as &$p) {
        $p['group'] = groupCode($p['code']);
        $p['duplicate'] = $p['code'] !== '' && ($counts[strtoupper(trim($p['code']))] ?? 0) > 1;
        $p['incomplete'] = trim($p['code']) === '' || trim($p['description']) === '' || !(int) $p['media_count'];
    }
    unset($p);
    return $rows;
}
function mediaLabel(string $kind): string
{
    return ['photo' => 'صورة المنتج', 'drawing' => 'رسم فني', 'extra' => 'صورة إضافية'][$kind] ?? 'مرفق';
}

// Safely delete catalog/upload files only after their last database reference is gone.
function deleteMediaFile(PDO|JsonCatalogStore $db, string $path): void
{
    $normalizedPath = ltrim(str_replace('\\', '/', $path), '/');
    if (catalogRow($db, 'media', ['path'=>$path]) !== false)
        return;
    $fullPath = realpath(__DIR__ . '/' . $normalizedPath);
    if ($fullPath === false) return;
    foreach ($db instanceof JsonCatalogStore ? ['uploads/local'] : ['uploads', 'assets/catalog'] as $directory) {
        $root = realpath(__DIR__ . '/' . $directory);
        if ($root !== false && str_starts_with($fullPath, $root . DIRECTORY_SEPARATOR) && is_file($fullPath)) {
            @unlink($fullPath);
            return;
        }
    }
}

function requireProduct(PDO|JsonCatalogStore $db, int $id, bool $activeOnly = true): array
{
    $criteria = ['id'=>$id];
    if ($activeOnly) $criteria['archived'] = 0;
    $product = catalogRow($db, 'products', $criteria);
    if (!$product) throw new RuntimeException('المنتج غير موجود أو تم نقله للأرشيف');
    return $product;
}

function deleteProductRecord(PDO|JsonCatalogStore $db, int $id): void
{
    $db->beginTransaction();
    try {
        requireProduct($db, $id, false);
        $media = mediaFor($db, $id);
        catalogDelete($db, 'media', ['product_id'=>$id]);
        catalogDelete($db, 'products', ['id'=>$id]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    foreach (array_unique(array_column($media, 'path')) as $path) deleteMediaFile($db, $path);
}

function changeMedia(PDO|JsonCatalogStore $db, string $op, int $productId, int $mediaId, string $kind = 'photo'): array
{
    if (!in_array($op, ['add', 'replace', 'delete'], true)) throw new RuntimeException('عملية غير صالحة');
    if ($op !== 'add' && $mediaId <= 0) throw new RuntimeException('معرف الصورة مطلوب');
    if (!in_array($kind, ['photo', 'drawing', 'extra'], true)) throw new RuntimeException('نوع غير صالح');
    requireProduct($db, $productId);
    $old = null;
    if ($op !== 'add') {
        $old = catalogRow($db, 'media', ['id'=>$mediaId,'product_id'=>$productId]);
        if (!$old) throw new RuntimeException('الصورة غير موجودة');
    }
    $files = $op === 'delete' ? [] : uploadFiles('file');
    if ($op !== 'delete' && count($files) !== 1) {
        foreach ($files as $file) deleteMediaFile($db, $file['path']);
        throw new RuntimeException('اختر صورة واحدة للرفع');
    }
    try {
        $db->beginTransaction();
        requireProduct($db, $productId);
        if ($op !== 'add') {
            $old = catalogRow($db, 'media', ['id'=>$mediaId,'product_id'=>$productId]);
            if (!$old) throw new RuntimeException('الصورة غير موجودة');
        }
        if ($op === 'delete') {
            catalogDelete($db, 'media', ['id'=>$mediaId,'product_id'=>$productId]);
        } elseif ($op === 'replace') {
            $file = $files[0];
            catalogUpdate($db, 'media', ['path'=>$file['path'],'sha256'=>$file['sha256'],'kind'=>$kind,'original_name'=>$file['original_name']], ['id'=>$mediaId,'product_id'=>$productId]);
        } else {
            $file = $files[0];
            $sort = 0;
            foreach (mediaFor($db, $productId) as $m) $sort = max($sort, (int)$m['sort_order']);
            $mediaId = catalogInsert($db, 'media', ['product_id'=>$productId,'path'=>$file['path'],'original_name'=>$file['original_name'],'kind'=>$kind,'caption'=>'','sort_order'=>$sort+1,'previewable'=>1,'sha256'=>$file['sha256']]);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        foreach ($files as $file) deleteMediaFile($db, $file['path']);
        throw $e;
    }
    if ($old) deleteMediaFile($db, $old['path']);
    return ['ok'=>true, 'op'=>$op, 'media_id'=>$mediaId] + ($files ? ['path'=>$files[0]['path']] : []);
}

function catalogUploadDirectory(): string
{
    global $config;
    $directory = $config['driver'] === 'json' ? 'uploads/local/' : 'uploads/';
    if (!is_dir(__DIR__ . '/' . $directory) && !@mkdir(__DIR__ . '/' . $directory, 0755, true)) throw new RuntimeException('تعذر إنشاء مجلد الصور');
    return $directory;
}
