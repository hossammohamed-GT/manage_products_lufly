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

// Auto-detect: if driver is mysql but MySQL is unreachable, silently fall back to SQLite
if (($config['driver'] ?? 'mysql') === 'mysql') {
    try {
        $testPdo = @new PDO('mysql:host=' . $config['host'] . ';port=' . (int)$config['port'], $config['username'] ?? 'root', $config['password'] ?? '', [PDO::ATTR_TIMEOUT => 2]);
        $testPdo = null; // MySQL is reachable, keep mysql driver
    } catch (Throwable $e) {
        // MySQL not reachable, fall back to SQLite automatically
        $config['driver'] = 'sqlite';
        if (empty($config['sqlite_path']))
            $config['sqlite_path'] = __DIR__ . '/data/lufly.sqlite';
    }
}
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
function db(bool $serverOnly = false): PDO
{
    global $config;
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
function setting(PDO $db, string $key, string $default = ''): string
{
    try {
        $v = run($db, 'SELECT value FROM settings WHERE name=?', [$key])->fetchColumn();
        return $v === false ? $default : (string) $v;
    } catch (Throwable $e) {
        return $default;
    }
}
function setSetting(PDO $db, string $key, string $value): void
{
    if (run($db, 'SELECT name FROM settings WHERE name=?', [$key])->fetchColumn() === false)
        run($db, 'INSERT INTO settings (name,value) VALUES (?,?)', [$key, $value]);
    else
        run($db, 'UPDATE settings SET value=? WHERE name=?', [$value, $key]);
}
function install(): void
{
    global $config;
    $db = db(true);
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
        // Preserve hosting edits carried by the seed (archived/verified/version/timestamps);
        // fall back to fresh-install defaults for older seed files that lack them.
        foreach ($seed['products'] as $p)
            run($db, 'INSERT INTO products (id,code,brand,status,description,notes,source_file,source_row,source_extra,archived,verified,version,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [$p['id'], $p['code'], $p['brand'], $p['status'], $p['description'], $p['notes'], $p['source_file'], $p['source_row'], $p['source_extra'], (int) ($p['archived'] ?? 0), (int) ($p['verified'] ?? 0), max(1, (int) ($p['version'] ?? 1)), $p['created_at'] ?? $now, $p['updated_at'] ?? $now]);
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
function mediaFor(PDO $db, int $id): array
{
    return run($db, 'SELECT * FROM media WHERE product_id=? ORDER BY sort_order,id', [$id])->fetchAll();
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
            $path = 'uploads/' . bin2hex(random_bytes(18)) . '.' . $allowed[$mime];
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
function saveProduct(PDO $db): int
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
            $s = run($db, 'UPDATE products SET code=?,brand=?,status=?,description=?,notes=?,version=version+1,updated_at=? WHERE id=? AND version=? AND archived=0', [$code, $brand, $status, $description, $notes, $now, $id, (int) ($_POST['version'] ?? 0)]);
            if ($s->rowCount() !== 1)
                throw new RuntimeException('تم تعديل المنتج في نافذة أخرى أو نقله للأرشيف. حدّث الصفحة قبل الحفظ حتى لا تفقد تعديلات أخرى.');
        } else {
            run($db, 'INSERT INTO products (code,brand,status,description,notes,source_extra,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?)', [$code, $brand, $status, $description, $notes, '{}', $now, $now]);
            $id = (int) $db->lastInsertId();
        }
        foreach (mediaFor($db, $id) as $m) {
            $mid = $m['id'];
            if (isset($_POST['remove_media'][$mid])) {
                run($db, 'DELETE FROM media WHERE id=? AND product_id=?', [$mid, $id]);
                $removedPaths[] = $m['path'];
                continue;
            }
            $caption = (string) ($_POST['caption'][$mid] ?? $m['caption']);
            $type = (string) ($_POST['kind'][$mid] ?? $m['kind']);
            if (strlen($caption) > 255 || !in_array($type, ['photo', 'drawing', 'extra'], true))
                throw new RuntimeException('بيانات المرفق غير صالحة.');
            run($db, 'UPDATE media SET caption=?,kind=?,sort_order=? WHERE id=? AND product_id=?', [$caption, $type, (int) ($_POST['sort'][$mid] ?? $m['sort_order']), $mid, $id]);
        }
        foreach ($files as $i => $m)
            run($db, 'INSERT INTO media (product_id,path,original_name,kind,caption,sort_order,previewable,source_column,source_row,sha256) VALUES (?,?,?,?,?,?,?,?,?,?)', [$id, $m['path'], $m['original_name'], $kind, '', 10000 + $i, 1, '', 0, $m['sha256']]);
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
function allProducts(PDO $db, bool $archived = false): array
{
    $rows = run($db, 'SELECT p.*, (SELECT COUNT(*) FROM media m WHERE m.product_id=p.id) AS media_count, (SELECT path FROM media m WHERE m.product_id=p.id AND m.previewable=1 ORDER BY CASE WHEN kind=\'photo\' THEN 0 ELSE 1 END,sort_order,id LIMIT 1) AS cover FROM products p WHERE archived=? ORDER BY id', [(int) $archived])->fetchAll();
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
function deleteMediaFile(PDO $db, string $path): void
{
    $normalizedPath = ltrim(str_replace('\\', '/', $path), '/');
    if ((int)run($db, 'SELECT COUNT(*) FROM media WHERE path=?', [$path])->fetchColumn() > 0)
        return;
    $fullPath = realpath(__DIR__ . '/' . $normalizedPath);
    if ($fullPath === false) return;
    foreach (['uploads', 'assets/catalog'] as $directory) {
        $root = realpath(__DIR__ . '/' . $directory);
        if ($root !== false && str_starts_with($fullPath, $root . DIRECTORY_SEPARATOR) && is_file($fullPath)) {
            @unlink($fullPath);
            return;
        }
    }
}

function requireProduct(PDO $db, int $id, bool $activeOnly = true): array
{
    $product = run($db, 'SELECT * FROM products WHERE id=?' . ($activeOnly ? ' AND archived=0' : ''), [$id])->fetch();
    if (!$product) throw new RuntimeException('المنتج غير موجود أو تم نقله للأرشيف');
    return $product;
}

function deleteProductRecord(PDO $db, int $id): void
{
    $db->beginTransaction();
    try {
        requireProduct($db, $id, false);
        $media = mediaFor($db, $id);
        run($db, 'DELETE FROM media WHERE product_id=?', [$id]);
        run($db, 'DELETE FROM products WHERE id=?', [$id]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    foreach (array_unique(array_column($media, 'path')) as $path) deleteMediaFile($db, $path);
}

function changeMedia(PDO $db, string $op, int $productId, int $mediaId, string $kind = 'photo'): array
{
    if (!in_array($op, ['add', 'replace', 'delete'], true)) throw new RuntimeException('عملية غير صالحة');
    if ($op !== 'add' && $mediaId <= 0) throw new RuntimeException('معرف الصورة مطلوب');
    if (!in_array($kind, ['photo', 'drawing', 'extra'], true)) throw new RuntimeException('نوع غير صالح');
    requireProduct($db, $productId);
    $old = null;
    if ($op !== 'add') {
        $old = run($db, 'SELECT * FROM media WHERE id=? AND product_id=?', [$mediaId, $productId])->fetch();
        if (!$old) throw new RuntimeException('الصورة غير موجودة');
    }
    $files = $op === 'delete' ? [] : uploadFiles('file');
    if ($op !== 'delete' && count($files) !== 1) {
        foreach ($files as $file) deleteMediaFile($db, $file['path']);
        throw new RuntimeException('اختر صورة واحدة للرفع');
    }
    try {
        $db->beginTransaction();
        if ($op === 'delete') {
            run($db, 'DELETE FROM media WHERE id=? AND product_id=?', [$mediaId, $productId]);
        } elseif ($op === 'replace') {
            $file = $files[0];
            run($db, 'UPDATE media SET path=?,sha256=?,kind=?,original_name=? WHERE id=? AND product_id=?',
                [$file['path'], $file['sha256'], $kind, $file['original_name'], $mediaId, $productId]);
        } else {
            $file = $files[0];
            $sort = (int)run($db, 'SELECT MAX(sort_order) FROM media WHERE product_id=?', [$productId])->fetchColumn();
            run($db, 'INSERT INTO media (product_id,path,original_name,kind,caption,sort_order,previewable,sha256) VALUES (?,?,?,?,?,?,1,?)',
                [$productId, $file['path'], $file['original_name'], $kind, '', $sort + 1, $file['sha256']]);
            $mediaId = (int)$db->lastInsertId();
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
