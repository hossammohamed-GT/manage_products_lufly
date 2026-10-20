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
function flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'] = $message;
    $_SESSION['flash_type'] = $type === 'error' ? 'error' : 'success';
}
function groupCode(string $code): string
{
    $code = trim($code);
    $pos = strpos($code, '-');
    return $pos !== false && $pos > 0 ? strtoupper(trim(substr($code, 0, $pos))) : 'غير مصنف';
}
function categorySchemaReady(PDO $db): bool
{
    static $checked = false;
    static $ready = false;
    if ($checked)
        return $ready;
    $checked = true;
    try {
        $ready = (int)$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('categories','product_categories')")->fetchColumn() === 2;
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}
function categoryRows(PDO $db): array
{
    if (!categorySchemaReady($db))
        return [];
    return $db->query('SELECT id,category_key,parent_id,name,sort_order FROM categories ORDER BY COALESCE(parent_id,0),sort_order,id')->fetchAll();
}
function categoryTree(PDO $db): array
{
    $byParent = [];
    foreach (categoryRows($db) as $row) {
        $parentId = $row['parent_id'] === null ? 0 : (int)$row['parent_id'];
        $byParent[$parentId][] = [
            'id' => (int)$row['id'],
            'parent_id' => $parentId ?: null,
            'name' => (string)$row['name'],
            'children' => [],
        ];
    }
    $build = function (int $parentId) use (&$build, $byParent): array {
        $nodes = $byParent[$parentId] ?? [];
        foreach ($nodes as &$node)
            $node['children'] = $build($node['id']);
        unset($node);
        return $nodes;
    };
    return $build(0);
}
function categoryDepth(PDO $db, int $categoryId): int
{
    $depth = 0;
    $visited = [];
    while ($categoryId > 0) {
        if (isset($visited[$categoryId]))
            throw new RuntimeException('يوجد تكرار غير صالح في مستويات التصنيفات.');
        $visited[$categoryId] = true;
        $row = run($db, 'SELECT parent_id FROM categories WHERE id=?', [$categoryId])->fetch();
        if (!$row)
            return 0;
        $depth++;
        $categoryId = $row['parent_id'] === null ? 0 : (int)$row['parent_id'];
    }
    return $depth;
}
function categoryPathFromId(int $categoryId, array $byId): array
{
    $path = [];
    $visited = [];
    while ($categoryId > 0 && isset($byId[$categoryId]) && !isset($visited[$categoryId]) && count($path) < 3) {
        $visited[$categoryId] = true;
        $row = $byId[$categoryId];
        $path[] = ['id' => (int)$row['id'], 'name' => (string)$row['name']];
        $categoryId = $row['parent_id'] === null ? 0 : (int)$row['parent_id'];
    }
    return array_reverse($path);
}
function cleanCategoryName(string $name): string
{
    $name = trim($name);
    $length = preg_match_all('/./us', $name, $matches);
    if ($name === '' || $length === false || $length > 190 || preg_match('/[\\x00-\\x1F\\x7F]/', $name))
        throw new RuntimeException('اكتب اسمًا صالحًا للتصنيف (حتى 190 حرفًا).');
    return $name;
}
function createCategoryNode(PDO $db, int $parentId, string $name): int
{
    if (!categorySchemaReady($db))
        throw new RuntimeException('شغّل ملف database/migrations/001_category_hierarchy.sql أولًا.');
    $name = cleanCategoryName($name);
    if ($parentId < 0)
        throw new RuntimeException('التصنيف الأب غير صالح.');
    $parentValue = null;
    if ($parentId > 0) {
        if (categoryDepth($db, $parentId) < 1)
            throw new RuntimeException('التصنيف الأب غير موجود.');
        if (categoryDepth($db, $parentId) >= 3)
            throw new RuntimeException('الحد الأقصى ثلاثة مستويات.');
        $parentValue = $parentId;
    }
    if (run($db, 'SELECT id FROM categories WHERE parent_id <=> ? AND name=? LIMIT 1', [$parentValue, $name])->fetchColumn() !== false)
        throw new RuntimeException('يوجد تصنيف بالاسم نفسه تحت هذا الأب بالفعل.');
    $sortOrder = $parentValue === null
        ? (int)$db->query('SELECT COALESCE(MAX(sort_order),0)+1 FROM categories WHERE parent_id IS NULL')->fetchColumn()
        : (int)run($db, 'SELECT COALESCE(MAX(sort_order),0)+1 FROM categories WHERE parent_id=?', [$parentValue])->fetchColumn();
    run($db, 'INSERT INTO categories (category_key,parent_id,name,sort_order) VALUES (?,?,?,?)', ['custom-' . bin2hex(random_bytes(12)), $parentValue, $name, $sortOrder]);
    return (int)$db->lastInsertId();
}
function renameCategoryNode(PDO $db, int $categoryId, string $name): void
{
    if (!categorySchemaReady($db))
        throw new RuntimeException('شغّل ملف database/migrations/001_category_hierarchy.sql أولًا.');
    $name = cleanCategoryName($name);
    $row = run($db, 'SELECT parent_id FROM categories WHERE id=?', [$categoryId])->fetch();
    if (!$row)
        throw new RuntimeException('التصنيف غير موجود.');
    $parentValue = $row['parent_id'] === null ? null : (int)$row['parent_id'];
    if (run($db, 'SELECT id FROM categories WHERE parent_id <=> ? AND name=? AND id<>? LIMIT 1', [$parentValue, $name, $categoryId])->fetchColumn() !== false)
        throw new RuntimeException('يوجد تصنيف بالاسم نفسه تحت هذا الأب بالفعل.');
    run($db, 'UPDATE categories SET name=? WHERE id=?', [$name, $categoryId]);
}
function deleteCategoryNode(PDO $db, int $categoryId): void
{
    if (!categorySchemaReady($db))
        throw new RuntimeException('شغّل ملف database/migrations/001_category_hierarchy.sql أولًا.');
    if (!run($db, 'SELECT id FROM categories WHERE id=?', [$categoryId])->fetchColumn())
        throw new RuntimeException('التصنيف غير موجود.');
    if ((int)run($db, 'SELECT COUNT(*) FROM categories WHERE parent_id=?', [$categoryId])->fetchColumn() > 0)
        throw new RuntimeException('احذف التصنيفات التابعة أولًا، من المستوى الثالث إلى الأعلى.');
    if ((int)run($db, 'SELECT COUNT(*) FROM product_categories WHERE category_id=?', [$categoryId])->fetchColumn() > 0)
        throw new RuntimeException('انقل المنتجات إلى تصنيف آخر أو أزل تصنيفها قبل الحذف.');
    run($db, 'DELETE FROM categories WHERE id=?', [$categoryId]);
}
function assignProductCategory(PDO $db, int $productId, int $categoryId): array
{
    if (!categorySchemaReady($db))
        throw new RuntimeException('شغّل ملف database/migrations/001_category_hierarchy.sql أولًا.');
    if ($categoryId < 0)
        throw new RuntimeException('معرف التصنيف غير صالح.');
    $db->beginTransaction();
    try {
        requireProduct($db, $productId);
        if ($categoryId <= 0) {
            run($db, 'DELETE FROM product_categories WHERE product_id=?', [$productId]);
            $categoryId = 0;
        } else {
            if (categoryDepth($db, $categoryId) < 1 || categoryDepth($db, $categoryId) > 3)
                throw new RuntimeException('التصنيف المحدد غير صالح.');
            run($db, 'INSERT INTO product_categories (product_id,category_id) VALUES (?,?) ON DUPLICATE KEY UPDATE category_id=VALUES(category_id)', [$productId, $categoryId]);
        }
        $updated = run($db, 'UPDATE products SET version=version+1,updated_at=? WHERE id=? AND archived=0', [date('c'), $productId]);
        if ($updated->rowCount() !== 1)
            throw new RuntimeException('المنتج غير موجود أو تم نقله للأرشيف.');
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction())
            $db->rollBack();
        throw $e;
    }
    $rows = categoryRows($db);
    $byId = [];
    foreach ($rows as $row)
        $byId[(int)$row['id']] = $row;
    $version = (int)run($db, 'SELECT version FROM products WHERE id=?', [$productId])->fetchColumn();
    return ['category_id' => $categoryId, 'category_path' => categoryPathFromId($categoryId, $byId), 'version' => $version];
}
function db(bool $serverOnly = false): PDO
{
    global $config;
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $config['database']))
        throw new RuntimeException('اسم قاعدة البيانات غير صالح.');
    $dsn = 'mysql:host=' . $config['host'] . ';port=' . (int) $config['port'] . ($serverOnly ? '' : ';dbname=' . $config['database']) . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_TIMEOUT => 3,
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
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
    $db->exec('CREATE DATABASE IF NOT EXISTS `' . $config['database'] . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $db = db();
    $sql = file_get_contents(__DIR__ . '/database/schema.sql');
    if ($sql === false)
        throw new RuntimeException('تعذر قراءة مخطط قاعدة البيانات.');
    foreach (explode(';', $sql) as $part)
        if (trim($part) !== '' && !preg_match('/^\s*(CREATE DATABASE|USE )/i', $part))
            $db->exec($part);

    // Add compatibility for existing MySQL schemas without changing their data.
    try {
        $db->exec('ALTER TABLE products ADD COLUMN verified INT NOT NULL DEFAULT 0');
    } catch (Throwable $e) {
    }
    try {
        $db->exec('ALTER TABLE products ADD INDEX idx_verified (verified)');
    } catch (Throwable $e) {
    }
}
function mediaFor(PDO $db, int $id): array
{
    return run($db, 'SELECT * FROM media WHERE product_id=? ORDER BY sort_order,id', [$id])->fetchAll();
}
function mediaPathExists(string $path): bool
{
    $path = trim($path);
    if ($path === '')
        return false;
    if (preg_match('~^https?://~i', $path))
        return true;
    $relativePath = ltrim(str_replace(chr(92), '/', $path), '/');
    if ($relativePath === '' || in_array('..', explode('/', $relativePath), true) || in_array('.', explode('/', $relativePath), true))
        return false;
    $root = realpath(__DIR__);
    $fullPath = realpath(__DIR__ . '/' . $relativePath);
    return $root !== false && $fullPath !== false && is_file($fullPath)
        && str_starts_with($fullPath, $root . DIRECTORY_SEPARATOR);
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
    $rows = run($db, 'SELECT p.*, (SELECT COUNT(*) FROM media m WHERE m.product_id=p.id) AS media_count FROM products p WHERE archived=? ORDER BY id', [(int) $archived])->fetchAll();
    $categoryByProduct = [];
    $categoryById = [];
    $categoryReady = categorySchemaReady($db);
    if ($rows && $categoryReady) {
        foreach (categoryRows($db) as $category)
            $categoryById[(int)$category['id']] = $category;
        $productIds = array_map(fn($row) => (int)$row['id'], $rows);
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        foreach (run($db, "SELECT product_id,category_id FROM product_categories WHERE product_id IN ($placeholders)", $productIds)->fetchAll() as $assignment)
            $categoryByProduct[(int)$assignment['product_id']] = (int)$assignment['category_id'];
    }
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
        $p['category_ready'] = $categoryReady;
        $p['category_id'] = $categoryByProduct[(int)$p['id']] ?? 0;
        $p['category_path'] = categoryPathFromId((int)$p['category_id'], $categoryById);
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
