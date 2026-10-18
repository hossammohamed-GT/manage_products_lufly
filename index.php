<?php
declare(strict_types=1);
require __DIR__.'/app.php';

$action = $_GET['action'] ?? 'home';
$page = max(1, (int)($_GET['page'] ?? 1));

try {
    $needsInstall = false;
    try {
        $testDb = db();
        $testDb->query('SELECT COUNT(*) FROM products');
    } catch (Throwable $e) { $needsInstall = true; }
    if ($needsInstall) {
        try { install(); } catch (Throwable $e) {
            $action = 'setup_error';
            $setupError = $e->getMessage();
        }
    }

    // API endpoints
    if (in_array($action, ['inline_save','media_action']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            checkCsrf();
            $d = db();
            
            if ($action === 'inline_save') {
                $id = (int)($_POST['id'] ?? 0);
                $field = (string)($_POST['field'] ?? '');
                $value = trim((string)($_POST['value'] ?? ''));
                $allowed = ['code','brand','description','notes','status'];
                if (!in_array($field, $allowed)) throw new RuntimeException('حقل غير صالح');
                if ($field === 'status' && !in_array($value, STATUSES, true)) throw new RuntimeException('حالة غير صالحة');
                if (strlen($value) > 60000) throw new RuntimeException('النص طويل جداً');
                $now = date('c');
                $s = run($d, "UPDATE products SET $field=?, version=version+1, updated_at=? WHERE id=? AND archived=0", [$value, $now, $id]);
                if ($s->rowCount() !== 1) throw new RuntimeException('المنتج غير موجود');
                echo json_encode(['ok'=>true, 'field'=>$field, 'value'=>$value]);
            }
            else if ($action === 'media_action') {
                $mediaId = (int)($_POST['media_id'] ?? 0);
                $productId = (int)($_POST['product_id'] ?? 0);
                $op = (string)($_POST['op'] ?? '');
                
                if (!in_array($op, ['replace','delete','add'])) throw new RuntimeException('عملية غير صالحة');
                if ($op !== 'add' && !$mediaId) throw new RuntimeException('معرف الصورة مطلوب');
                
                if ($op === 'delete') {
                    $m = run($d, 'SELECT * FROM media WHERE id=? AND product_id=?', [$mediaId, $productId])->fetch();
                    if (!$m) throw new RuntimeException('الصورة غير موجودة');
                    @unlink(__DIR__.'/'.ltrim($m['path'],'/'));
                    run($d, 'DELETE FROM media WHERE id=?', [$mediaId]);
                    echo json_encode(['ok'=>true, 'op'=>'delete', 'media_id'=>$mediaId]);
                } else {
                    $kind = (string)($_POST['kind'] ?? 'photo');
                    if (!in_array($kind, ['photo','drawing','extra'])) throw new RuntimeException('نوع غير صالح');
                    
                    if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                        throw new RuntimeException('لم يتم رفع ملف');
                    }
                    $f = $_FILES['file'];
                    if ($f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('خطأ في الرفع');
                    if ((int)$f['size'] > 12*1024*1024) throw new RuntimeException('الحجم أكبر من 12MB');
                    
                    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
                    $allowedMime = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'];
                    if (!isset($allowedMime[$mime]) || @getimagesize($f['tmp_name'])===false) throw new RuntimeException('JPG/PNG/WebP/GIF فقط');
                    
                    $ext = $allowedMime[$mime];
                    $newPath = 'uploads/'.bin2hex(random_bytes(18)).'.'.$ext;
                    if (!is_dir(__DIR__.'/uploads')) mkdir(__DIR__.'/uploads', 0755, true);
                    if (!move_uploaded_file($f['tmp_name'], __DIR__.'/'.ltrim($newPath,'/'))) throw new RuntimeException('تعذر حفظ الصورة');
                    
                    $sha = hash_file('sha256', __DIR__.'/'.ltrim($newPath,'/'));
                    $origName = substr(basename(str_replace('\\','/',$f['name'])),0,240);
                    
                    if ($op === 'replace') {
                        $m = run($d, 'SELECT * FROM media WHERE id=? AND product_id=?', [$mediaId, $productId])->fetch();
                        if (!$m) { @unlink(__DIR__.'/'.ltrim($newPath,'/')); throw new RuntimeException('الصورة غير موجودة'); }
                        @unlink(__DIR__.'/'.ltrim($m['path'],'/'));
                        run($d, 'UPDATE media SET path=?, sha256=?, kind=?, original_name=? WHERE id=?', [$newPath, $sha, $kind, $origName, $mediaId]);
                        echo json_encode(['ok'=>true, 'op'=>'replace', 'media_id'=>$mediaId, 'path'=>$newPath]);
                    } else {
                        $maxSort = (int)$d->query('SELECT MAX(sort_order) FROM media WHERE product_id='.$productId)->fetchColumn();
                        run($d, 'INSERT INTO media (product_id,path,original_name,kind,caption,sort_order,previewable,sha256) VALUES (?,?,?,?,?,?,1,?)',
                            [$productId, $newPath, $origName, $kind, '', $maxSort+1, $sha]);
                        $newId = (int)$d->lastInsertId();
                        echo json_encode(['ok'=>true, 'op'=>'add', 'media_id'=>$newId, 'path'=>$newPath]);
                    }
                }
            }
        } catch (Throwable $e) {
            http_response_code(400);
            echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
        }
        exit;
    }

    switch ($action) {
        case 'home': showHome($page); break;
        case 'group': showGroup($_GET['prefix'] ?? '', $page); break;
        case 'search': showSearch($_GET['q'] ?? '', $page); break;
        case 'archive':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                checkCsrf();
                $id = (int)($_POST['id'] ?? 0);
                if ($id) { run(db(), 'UPDATE products SET archived=1 WHERE id=?', [$id]); flash('تم أرشفة المنتج'); }
                go('?action=home');
            }
            break;
        case 'restore':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                checkCsrf();
                $id = (int)($_POST['id'] ?? 0);
                if ($id) { run(db(), 'UPDATE products SET archived=0 WHERE id=?', [$id]); flash('تم الاستعادة'); }
                go('?action=home');
            }
            break;
        case 'delete':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                checkCsrf();
                $id = (int)($_POST['id'] ?? 0);
                if ($id) {
                    $media = mediaFor(db(), $id);
                    foreach ($media as $m) @unlink(__DIR__ . '/' . $m['path']);
                    run(db(), 'DELETE FROM products WHERE id=?', [$id]);
                    flash('تم الحذف نهائياً');
                }
                go('?action=home');
            }
            break;
        case 'settings':
        case 'save_settings':
        case 'archive_list':
        case 'reinstall':
        case 'setup_error':
            // These will show as modals or be handled in the main view
            showHome($page);
            break;
        default: showHome(1);
    }
} catch (Throwable $e) {
    showError($e->getMessage());
}

// ═══════════════════════════════════════════════════════════
function layout(string $title, string $content, array $groups = [], string $extraJs = '', string $action = 'home'): void
{
    require_once __DIR__.'/includes/sidebar.php';
    $d = db();
    $siteTitle = setting($d, 'site_title', 'LUFLY Catalog');
    $primary = setting($d, 'primary_color', '#1F9E9E');
    $secondary = setting($d, 'secondary_color', '#147070');
    $flash = $_SESSION['flash'] ?? '';
    unset($_SESSION['flash']);
    $sidebarHtml = renderSidebar($groups, (string)($_GET['prefix'] ?? ''));
    ?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($title) ?> - <?= h($siteTitle) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root {
    --primary: <?= h($primary) ?>;
    --primary-dark: <?= h($secondary) ?>;
    --primary-light: <?= h($primary) ?>15;
    --bg: #f8f9fa;
    --card: #ffffff;
    --text: #1a1a2e;
    --text-muted: #6b7280;
    --border: #e8ecf1;
    --success: #10b981;
    --warning: #f59e0b;
    --danger: #ef4444;
    --radius: 14px;
    --shadow: 0 2px 12px rgba(0,0,0,0.06);
    --shadow-lg: 0 12px 32px rgba(0,0,0,0.12);
}
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: 'Segoe UI', Tahoma, Arial, sans-serif; background: var(--bg); color: var(--text); line-height: 1.6; }

/* ══ HEADER ═══ */
.top-header {
    background: white;
    border-bottom: 1px solid var(--border);
    padding: 12px 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: sticky;
    top: 0;
    z-index: 100;
}
.top-header .logo { display: flex; align-items: center; gap: 10px; text-decoration: none; color: var(--text); }
.top-header .logo img { height: 36px; }
.top-header .logo-text { font-weight: 800; font-size: 1.1rem; color: var(--primary); letter-spacing: 1px; }
.top-nav { display: flex; gap: 6px; }
.top-nav a { color: var(--text-muted); text-decoration: none; padding: 8px 14px; border-radius: 8px; font-size: 0.9rem; transition: all 0.2s; cursor: pointer; }
.top-nav a:hover, .top-nav a.active { background: var(--primary-light); color: var(--primary); }

/* ═══ HERO BANNER ═══ */
.hero-banner {
    background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
    padding: 40px 24px;
    color: white;
    position: relative;
    overflow: hidden;
}
.hero-banner::before { content: ''; position: absolute; top: -50%; left: -10%; width: 500px; height: 500px; background: rgba(255,255,255,0.06); border-radius: 50%; }
.hero-banner::after { content: ''; position: absolute; bottom: -60%; right: -5%; width: 400px; height: 400px; background: rgba(255,255,255,0.04); border-radius: 50%; }
.hero-inner { max-width: 1400px; margin: 0 auto; display: flex; align-items: center; justify-content: space-between; gap: 32px; position: relative; z-index: 1; }
.hero-text h1 { font-size: 2.2rem; font-weight: 800; margin-bottom: 8px; }
.hero-text h1 span { display: block; font-size: 1.1rem; font-weight: 500; opacity: 0.9; margin-top: 8px; }
.hero-stats { display: flex; gap: 32px; margin-top: 20px; }
.hero-stat { text-align: center; }
.hero-stat-num { font-size: 1.8rem; font-weight: 800; }
.hero-stat-label { font-size: 0.8rem; opacity: 0.85; }

/* ══ MAIN LAYOUT ══ */
.main-layout { display: flex; max-width: 1400px; margin: 0 auto; gap: 24px; padding: 24px; }
.main-content { flex: 1; min-width: 0; }

/* ═══ TOOLBAR ═══ */
.toolbar { background: white; border-radius: var(--radius); padding: 16px 20px; box-shadow: var(--shadow); display: flex; align-items: center; gap: 12px; margin-bottom: 20px; flex-wrap: wrap; }
.toolbar-title { font-weight: 700; font-size: 1.1rem; color: var(--text); }
.toolbar-count { color: var(--text-muted); font-size: 0.9rem; }
.toolbar-search { flex: 1; min-width: 200px; padding: 10px 16px; border: 2px solid var(--border); border-radius: 10px; font-size: 0.9rem; outline: none; direction: rtl; transition: border-color 0.2s; }
.toolbar-search:focus { border-color: var(--primary); }
.toolbar-select { padding: 10px 14px; border: 2px solid var(--border); border-radius: 10px; font-size: 0.9rem; background: white; outline: none; cursor: pointer; }

/* ═══ FLASH ═══ */
.flash { background: var(--success); color: white; padding: 12px 18px; border-radius: 10px; margin-bottom: 16px; font-weight: 600; font-size: 0.95rem; }

/* ═══ PRODUCTS GRID ══ */
.products-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 20px; }
.product-card {
    background: var(--card);
    border-radius: var(--radius);
    overflow: hidden;
    box-shadow: var(--shadow);
    transition: all 0.25s;
    display: flex;
    flex-direction: column;
}
.product-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-lg); }

/* Card Image Carousel */
.card-carousel {
    position: relative;
    height: 220px;
    background: #f0f2f5;
    overflow: hidden;
}
.card-carousel-imgs {
    display: flex;
    height: 100%;
    transition: transform 0.3s ease;
}
.card-carousel-imgs img {
    min-width: 100%;
    height: 100%;
    object-fit: cover;
    cursor: pointer;
}
.carousel-btn {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: rgba(255,255,255,0.9);
    border: none;
    cursor: pointer;
    font-size: 1rem;
    color: var(--text);
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
}
.card-carousel:hover .carousel-btn { opacity: 1; }
.carousel-btn:hover { background: white; box-shadow: 0 2px 8px rgba(0,0,0,0.2); }
.carousel-prev { right: 12px; }
.carousel-next { left: 12px; }
.carousel-dots {
    position: absolute;
    bottom: 12px;
    left: 50%;
    transform: translateX(-50%);
    display: flex;
    gap: 6px;
}
.carousel-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: rgba(255,255,255,0.5);
    transition: all 0.2s;
}
.carousel-dot.active { background: white; width: 20px; border-radius: 4px; }
.no-image {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #bbb;
    font-size: 3rem;
}

/* Status Badge */
.status-badge {
    position: absolute;
    top: 12px;
    right: 12px;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.72rem;
    font-weight: 700;
    color: white;
    z-index: 2;
}
.status-badge.Running { background: var(--success); }
.status-badge.New-Model { background: #3b82f6; }
.status-badge.Slow-Moving { background: var(--warning); }
.status-badge.Aging { background: var(--danger); }

/* Media Count */
.media-count {
    position: absolute;
    bottom: 12px;
    right: 12px;
    background: rgba(0,0,0,0.7);
    color: white;
    padding: 4px 10px;
    border-radius: 8px;
    font-size: 0.75rem;
    font-weight: 600;
    z-index: 2;
}

/* Card Body */
.product-card-body { padding: 16px; flex: 1; display: flex; flex-direction: column; gap: 12px; }

/* Inline Editable Fields */
.inline-field {
    padding: 8px 10px;
    border: 1px solid transparent;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.15s;
    position: relative;
}
.inline-field:hover {
    background: #f8f9fa;
    border-color: var(--border);
}
.inline-field-label {
    font-size: 0.72rem;
    color: var(--text-muted);
    font-weight: 600;
    margin-bottom: 4px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.inline-field-label i { color: var(--primary); font-size: 0.8rem; }
.inline-field-value {
    font-size: 0.95rem;
    color: var(--text);
    font-weight: 600;
}
.inline-field-value.code {
    font-family: 'Consolas', monospace;
    font-size: 1.1rem;
    color: var(--primary);
}
.inline-field-value.desc {
    font-weight: 400;
    color: var(--text-muted);
    font-size: 0.88rem;
    line-height: 1.5;
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

/* Inline Edit Form */
.inline-edit-form {
    display: none;
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: white;
    border: 2px solid var(--primary);
    border-radius: 10px;
    padding: 12px;
    box-shadow: var(--shadow-lg);
    z-index: 100;
    margin-top: 8px;
}
.inline-edit-form.active { display: block; }
.inline-edit-form input, .inline-edit-form textarea, .inline-edit-form select {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid var(--border);
    border-radius: 8px;
    font-size: 0.9rem;
    outline: none;
    direction: rtl;
    margin-bottom: 8px;
}
.inline-edit-form input:focus, .inline-edit-form textarea:focus, .inline-edit-form select:focus { border-color: var(--primary); }
.inline-edit-form textarea { min-height: 80px; resize: vertical; }
.inline-edit-actions { display: flex; gap: 8px; justify-content: flex-end; }
.inline-edit-actions button {
    padding: 6px 14px;
    border: none;
    border-radius: 8px;
    font-size: 0.82rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}
.btn-save { background: var(--primary); color: white; }
.btn-save:hover { background: var(--primary-dark); }
.btn-cancel { background: #f0f2f5; color: var(--text-muted); }
.btn-cancel:hover { background: #e5e7eb; }

/* Card Footer */
.product-card-footer {
    padding: 12px 16px;
    border-top: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 8px;
}
.card-actions { display: flex; gap: 6px; }
.card-btn {
    width: 32px;
    height: 32px;
    border: none;
    background: #f0f2f5;
    border-radius: 8px;
    cursor: pointer;
    font-size: 0.9rem;
    color: var(--text-muted);
    transition: all 0.15s;
    display: flex;
    align-items: center;
    justify-content: center;
}
.card-btn:hover { background: var(--primary); color: white; }
.card-btn.danger:hover { background: var(--danger); }

/* Upload Button */
.upload-btn {
    padding: 8px 14px;
    background: var(--primary-light);
    color: var(--primary);
    border: 2px dashed var(--primary);
    border-radius: 10px;
    cursor: pointer;
    font-size: 0.85rem;
    font-weight: 600;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    gap: 6px;
}
.upload-btn:hover {
    background: var(--primary);
    color: white;
}

/* ═══ LIGHTBOX ═══ */
.lightbox {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.92);
    z-index: 9999;
    align-items: center;
    justify-content: center;
    animation: lbFadeIn 0.2s ease;
}
.lightbox.active { display: flex; }
@keyframes lbFadeIn { from { opacity: 0; } to { opacity: 1; } }
.lightbox-close {
    position: absolute;
    top: 20px;
    left: 20px;
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background: rgba(255,255,255,0.15);
    border: none;
    color: white;
    font-size: 1.5rem;
    cursor: pointer;
    transition: background 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
}
.lightbox-close:hover { background: rgba(255,255,255,0.3); }
.lightbox-img {
    max-width: 90vw;
    max-height: 85vh;
    object-fit: contain;
    border-radius: 12px;
    box-shadow: 0 20px 60px rgba(0,0,0,0.5);
}
.lightbox-nav {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    width: 50px;
    height: 50px;
    border-radius: 50%;
    background: rgba(255,255,255,0.15);
    border: none;
    color: white;
    font-size: 1.5rem;
    cursor: pointer;
    transition: background 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
}
.lightbox-nav:hover { background: rgba(255,255,255,0.3); }
.lightbox-prev { right: 20px; }
.lightbox-next { left: 20px; }
.lightbox-actions {
    position: absolute;
    bottom: 24px;
    left: 50%;
    transform: translateX(-50%);
    display: flex;
    gap: 12px;
    background: rgba(0,0,0,0.7);
    padding: 12px 20px;
    border-radius: 16px;
    backdrop-filter: blur(12px);
}
.lightbox-action-btn {
    padding: 10px 18px;
    border: none;
    border-radius: 10px;
    font-size: 0.85rem;
    font-weight: 700;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s;
    font-family: inherit;
}
.lb-replace { background: var(--primary); color: white; }
.lb-replace:hover { background: var(--primary-dark); }
.lb-delete { background: var(--danger); color: white; }
.lb-delete:hover { background: #dc2626; }
.lightbox-counter {
    position: absolute;
    top: 24px;
    right: 24px;
    color: rgba(255,255,255,0.8);
    font-size: 0.9rem;
    font-weight: 600;
    background: rgba(0,0,0,0.5);
    padding: 6px 14px;
    border-radius: 20px;
}

/* ═══ TOAST ═══ */
.toast {
    position: fixed;
    bottom: 24px;
    left: 50%;
    transform: translateX(-50%);
    padding: 12px 24px;
    border-radius: 10px;
    color: white;
    font-weight: 600;
    z-index: 9999;
    transition: opacity 0.3s;
    box-shadow: 0 4px 12px rgba(0,0,0,0.2);
}
.toast.success { background: var(--success); }
.toast.error { background: var(--danger); }

/* ═══ FILTER TABS ═══ */
.filter-tabs { display: flex; gap: 8px; margin-bottom: 20px; flex-wrap: wrap; }
.filter-tab { padding: 7px 14px; border-radius: 20px; text-decoration: none; font-size: 0.82rem; font-weight: 600; background: white; color: var(--text); box-shadow: var(--shadow); transition: all 0.2s; cursor: pointer; }
.filter-tab:hover, .filter-tab.active { background: var(--primary); color: white; }

/* ═══ PAGINATION ═══ */
.pagination { display: flex; gap: 6px; justify-content: center; margin-top: 32px; flex-wrap: wrap; }
.pagination a, .pagination span { padding: 8px 14px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 0.9rem; transition: all 0.2s; }
.pagination a { background: white; color: var(--text); box-shadow: var(--shadow); }
.pagination a:hover { background: var(--primary); color: white; }
.pagination .current { background: var(--primary); color: white; }

/* ═══ RESPONSIVE ═══ */
@media (max-width: 700px) {
    .hero-inner { flex-direction: column; text-align: center; }
    .hero-text h1 { font-size: 1.5rem; }
    .hero-stats { justify-content: center; }
    .toolbar { flex-direction: column; align-items: stretch; }
    .products-grid { grid-template-columns: 1fr; }
    .top-header { flex-direction: column; gap: 8px; }
}
@media (max-width: 900px) { .sidebar { display: none; } }
</style>
</head>
<body>

<!-- ═══ TOP HEADER ═══ -->
<div class="top-header">
    <a href="?action=home" class="logo">
        <img src="<?= h($config['logo_path'] ?? 'assets/logo.png') ?>" alt="LUFLY">
        <span class="logo-text"><?= h($siteTitle) ?></span>
    </a>
    <nav class="top-nav">
        <a href="?action=home" class="<?= $action==='home'?'active':'' ?>"><i class="fas fa-home"></i> الرئيسية</a>
        <a href="#" onclick="showSettingsModal()"><i class="fas fa-cog"></i> الإعدادات</a>
    </nav>
</div>

<!-- ══ HERO BANNER ═══ -->
<?php if (in_array($action, ['home','group','search'])): ?>
<div class="hero-banner">
    <div class="hero-inner">
        <div class="hero-text">
            <h1>
                <?= $action === 'home' ? 'كتالوج منتجات LUFLY' : ($action === 'group' ? 'مجموعة: ' . h((string)($_GET['prefix'] ?? '')) : 'نتائج البحث') ?>
                <span>
                    <?= $action === 'home' ? 'إدارة المنتجات بالكامل من هنا — اضغط على أي حقل لتعديله مباشرة' : 'اضغط على أي منتج لعرض التفاصيل' ?>
                </span>
            </h1>
            <?php if ($action === 'home'): ?>
            <div class="hero-stats">
                <div class="hero-stat">
                    <div class="hero-stat-num"><?= count($groups) ?></div>
                    <div class="hero-stat-label">مجموعة كود</div>
                </div>
                <div class="hero-stat">
                    <div class="hero-stat-num"><?= array_sum($groups) ?></div>
                    <div class="hero-stat-label">منتج</div>
                </div>
                <div class="hero-stat">
                    <div class="hero-stat-num"><?= (int)$d->query('SELECT COUNT(*) FROM media')->fetchColumn() ?></div>
                    <div class="hero-stat-label">مرفق</div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ═══ MAIN LAYOUT ═══ -->
<div class="main-layout">
    <?= $sidebarHtml ?>
    <div class="main-content">
        <?php if ($flash): ?><div class="flash"><?= h($flash) ?></div><?php endif; ?>
        <?= $content ?>
    </div>
</div>

<!-- ═══ LIGHTBOX ═══ -->
<div class="lightbox" id="lightbox">
    <button class="lightbox-close" onclick="closeLightbox()"><i class="fas fa-times"></i></button>
    <span class="lightbox-counter" id="lbCounter"></span>
    <button class="lightbox-nav lightbox-prev" onclick="lbNav(-1)"><i class="fas fa-chevron-right"></i></button>
    <button class="lightbox-nav lightbox-next" onclick="lbNav(1)"><i class="fas fa-chevron-left"></i></button>
    <img class="lightbox-img" id="lbImg" src="" alt="">
    <div class="lightbox-actions" id="lbActions"></div>
</div>

<!-- ═══ TOAST ═══ -->
<div id="toast"></div>

<?= $extraJs ?>
<script>
const csrfToken = document.querySelector('meta[name="csrf"]')?.content || '';

// ═══ INLINE EDITING ═══
window.inlineEdit = function(el, field, productId, productVersion) {
    const form = el.querySelector('.inline-edit-form');
    if (!form) return;
    
    // Hide all other forms
    document.querySelectorAll('.inline-edit-form.active').forEach(f => {
        if (f !== form) f.classList.remove('active');
    });
    
    form.classList.add('active');
    const input = form.querySelector('input, textarea, select');
    if (input) input.focus();
    
    const saveBtn = form.querySelector('.btn-save');
    const cancelBtn = form.querySelector('.btn-cancel');
    
    saveBtn.onclick = function() {
        const value = input.value.trim();
        if (!value) return;
        
        const fd = new FormData();
        fd.append('csrf', csrfToken);
        fd.append('id', productId);
        fd.append('field', field);
        fd.append('value', value);
        
        showToast('جاري الحفظ...');
        
        fetch('?action=inline_save', { method:'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                const valueEl = el.querySelector('.inline-field-value');
                if (valueEl) {
                    valueEl.textContent = data.value;
                    valueEl.dataset.value = data.value;
                }
                form.classList.remove('active');
                showToast('تم الحفظ', 'success');
            } else {
                showToast(data.error || 'خطأ', 'error');
            }
        })
        .catch(() => showToast('خطأ في الاتصال', 'error'));
    };
    
    cancelBtn.onclick = function() {
        form.classList.remove('active');
    };
};

// ═══ LIGHTBOX ═══
const lbState = { idx: 0, total: 0, productId: 0, images: [], mediaIds: [] };

window.openLightbox = function(idx, total, productId, images, mediaIds) {
    lbState.idx = idx;
    lbState.total = total;
    lbState.productId = productId;
    lbState.images = images;
    lbState.mediaIds = mediaIds;
    updateLightbox();
    document.getElementById('lightbox').classList.add('active');
    document.body.style.overflow = 'hidden';
};

function updateLightbox() {
    document.getElementById('lbImg').src = lbState.images[lbState.idx];
    document.getElementById('lbCounter').textContent = (lbState.idx + 1) + ' / ' + lbState.total;
    document.getElementById('lbActions').innerHTML = `
        <button class="lightbox-action-btn lb-replace" onclick="triggerReplace()"><i class="fas fa-sync-alt"></i> استبدال</button>
        <button class="lightbox-action-btn lb-delete" onclick="deleteImage()"><i class="fas fa-trash"></i> حذف</button>
        <form class="lightbox-replace-form" id="lbReplaceForm" style="display:none;">
            <input type="file" id="lbReplaceFile" accept="image/*" required>
            <button type="button" onclick="cancelReplace()" style="background:#f0f2f5;border:none;padding:6px 12px;border-radius:8px;cursor:pointer;">إلغاء</button>
        </form>
    `;
    document.getElementById('lbReplaceFile').onchange = function() {
        if (this.files[0]) mediaAction('replace', 0, lbState.productId, this.files[0]);
    };
}

window.closeLightbox = function() {
    document.getElementById('lightbox').classList.remove('active');
    document.body.style.overflow = '';
};

window.lbNav = function(dir) {
    lbState.idx = (lbState.idx + dir + lbState.total) % lbState.total;
    updateLightbox();
};

window.triggerReplace = function() {
    document.getElementById('lbReplaceForm').style.display = 'flex';
    document.getElementById('lbReplaceFile').click();
};

window.cancelReplace = function() {
    document.getElementById('lbReplaceForm').style.display = 'none';
};

window.deleteImage = function() {
    if (!confirm('هل تريد حذف هذه الصورة؟')) return;
    const mediaId = lbState.mediaIds[lbState.idx];
    if (!mediaId) { alert('معرف الصورة غير متوفر'); return; }
    
    const fd = new FormData();
    fd.append('csrf', csrfToken);
    fd.append('op', 'delete');
    fd.append('media_id', mediaId);
    fd.append('product_id', lbState.productId);
    
    fetch('?action=media_action', { method:'POST', body: fd })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            lbState.images.splice(lbState.idx, 1);
            lbState.mediaIds.splice(lbState.idx, 1);
            lbState.total--;
            if (lbState.idx >= lbState.total) lbState.idx = Math.max(0, lbState.total - 1);
            if (lbState.total === 0) { closeLightbox(); location.reload(); }
            else updateLightbox();
            showToast('تم حذف الصورة', 'success');
        } else { showToast(data.error || 'خطأ', 'error'); }
    })
    .catch(() => showToast('خطأ في الاتصال', 'error'));
};

window.mediaAction = function(op, mediaId, productId, file) {
    if (!file) return;
    const fd = new FormData();
    fd.append('csrf', csrfToken);
    fd.append('op', op);
    fd.append('product_id', productId);
    if (mediaId) fd.append('media_id', mediaId);
    fd.append('file', file);
    fd.append('kind', 'photo');
    
    showToast(op === 'add' ? 'جاري الإضافة...' : 'جاري الاستبدال...');
    
    fetch('?action=media_action', { method:'POST', body: fd })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            if (op === 'add') { location.reload(); }
            else if (op === 'replace') {
                const ts = '?t=' + Date.now();
                lbState.images[lbState.idx] = data.path + ts;
                lbState.mediaIds[lbState.idx] = data.media_id;
                updateLightbox();
                showToast('تم استبدال الصورة', 'success');
                cancelReplace();
            }
        } else { showToast(data.error || 'خطأ', 'error'); }
    })
    .catch(() => showToast('خطأ في الاتصال', 'error'));
};

function showToast(msg, type='success') {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'toast ' + type;
    t.style.opacity = '1';
    setTimeout(() => { t.style.opacity = '0'; }, 2000);
}

document.addEventListener('keydown', function(e) {
    const lb = document.getElementById('lightbox');
    if (!lb.classList.contains('active')) return;
    if (e.key === 'Escape') closeLightbox();
    if (e.key === 'ArrowLeft') lbNav(1);
    if (e.key === 'ArrowRight') lbNav(-1);
});
document.getElementById('lightbox').addEventListener('click', function(e) {
    if (e.target === this) closeLightbox();
});
</script>
<meta name="csrf" content="<?= h(csrf()) ?>">
</body>
</html>
    <?php
}

// ═══════════════════════════════════════════════════════════
function productCardHTML(array $p): string {
    $d = db();
    $media = mediaFor($d, $p['id']);
    $previewable = array_values(array_filter($media, fn($m) => (int)$m['previewable']));
    
    // Build carousel
    $carouselImgs = '';
    $carouselDots = '';
    $mediaIds = [];
    foreach ($previewable as $i => $m) {
        $carouselImgs .= '<img src="'.h($m['path']).'" onclick="openLightbox('. $i.', '.count($previewable).', '.$p['id'].', '.json_encode(array_map(fn($x)=>h($x['path']), $previewable)).', '.json_encode(array_map(fn($x)=>(int)$x['id'], $previewable)).')">';
        $carouselDots .= '<span class="carousel-dot'.($i===0?' active':'').'"></span>';
        $mediaIds[] = (int)$m['id'];
    }
    
    $statusClass = str_replace(' ', '-', $p['status']);
    $statusText = ['Running'=>'نشط','New Model'=>'موديل جديد','Slow Moving'=>'بطيء','Aging'=>'قديم'][$p['status']] ?? $p['status'];
    
    $carouselBtns = count($previewable) > 1 ? '
        <button class="carousel-btn carousel-prev" onclick="event.stopPropagation();carouselPrev(this)"><i class="fas fa-chevron-right"></i></button>
        <button class="carousel-btn carousel-next" onclick="event.stopPropagation();carouselNext(this)"><i class="fas fa-chevron-left"></i></button>
        <div class="carousel-dots">'.$carouselDots.'</div>
    ' : '';
    
    $noImage = empty($previewable) ? '<div class="no-image"><i class="far fa-image"></i></div>' : '';
    
    return '<div class="product-card" data-id="'.$p['id'].'" data-version="'.$p['version'].'">'
        .'<div class="card-carousel">'
        .'<div class="card-carousel-imgs">'.$carouselImgs.$noImage.'</div>'
        .$carouselBtns
        .'<span class="status-badge '.$statusClass.'">'.$statusText.'</span>'
        .'<span class="media-count"><i class="fas fa-paperclip"></i> '.count($previewable).'</span>'
        .'</div>'
        .'<div class="product-card-body">'
        .'<div class="inline-field" onclick="inlineEdit(this,\'code\','.$p['id'].','.$p['version'].')">'
        .'<div class="inline-field-label"><i class="fas fa-hashtag"></i> الكود</div>'
        .'<div class="inline-field-value code" data-value="'.h($p['code']).'">'.h($p['code']?:'—').'</div>'
        .'<div class="inline-edit-form"><input type="text" value="'.h($p['code']).'"><div class="inline-edit-actions"><button class="btn-cancel">إلغاء</button><button class="btn-save">حفظ</button></div></div>'
        .'</div>'
        .'<div class="inline-field" onclick="inlineEdit(this,\'description\','.$p['id'].','.$p['version'].')">'
        .'<div class="inline-field-label"><i class="fas fa-align-right"></i> الوصف</div>'
        .'<div class="inline-field-value desc" data-value="'.h($p['description']).'">'.h($p['description']?:'— اضغط للتعديل —').'</div>'
        .'<div class="inline-edit-form"><textarea>'.h($p['description']).'</textarea><div class="inline-edit-actions"><button class="btn-cancel">إلغاء</button><button class="btn-save">حفظ</button></div></div>'
        .'</div>'
        .'<div class="inline-field" onclick="inlineEdit(this,\'status\','.$p['id'].','.$p['version'].')">'
        .'<div class="inline-field-label"><i class="fas fa-tag"></i> الحالة</div>'
        .'<div class="inline-field-value" data-value="'.h($p['status']).'"><span class="status-badge '.$statusClass.'">'.$statusText.'</span></div>'
        .'<div class="inline-edit-form"><select>'.implode('', array_map(fn($s)=>'<option value="'.$s.'"'.($s===$p['status']?' selected':'').'>'.$s.'</option>', STATUSES)).'</select><div class="inline-edit-actions"><button class="btn-cancel">إلغاء</button><button class="btn-save">حفظ</button></div></div>'
        .'</div>'
        .'<div style="padding:0 10px;"><label class="upload-btn"><i class="fas fa-upload"></i> رفع صورة<input type="file" accept="image/*" style="display:none" onchange="mediaAction(\'add\',0,'.$p['id'].',this.files[0])"></label></div>'
        .'</div>'
        .'<div class="product-card-footer">'
        .'<div class="card-actions">'
        .'<button class="card-btn" onclick="archiveProduct('.$p['id'].')" title="أرشفة"><i class="fas fa-archive"></i></button>'
        .'<button class="card-btn danger" onclick="deleteProduct('.$p['id'].')" title="حذف"><i class="fas fa-trash"></i></button>'
        .'</div>'
        .'</div>'
        .'</div>';
}

function renderProducts(array $products, int $page, int $totalPages, string $baseUrl): void {
    if (empty($products)) {
        echo '<div style="text-align:center; padding:60px; color:var(--text-muted);"><i class="fas fa-box-open" style="font-size:4rem; margin-bottom:16px;"></i><h3>لا توجد منتجات</h3></div>';
        return;
    }
    echo '<div class="products-grid">';
    foreach ($products as $p) echo productCardHTML($p);
    echo '</div>';
    
    if ($totalPages > 1) {
        echo '<div class="pagination">';
        if ($page > 1) echo '<a href="'.$baseUrl.'&page='.($page-1).'"><i class="fas fa-arrow-right"></i> السابق</a>';
        $start = max(1, $page-3); $end = min($totalPages, $page+3);
        for ($i = $start; $i <= $end; $i++) {
            if ($i === $page) echo '<span class="current">'.$i.'</span>';
            else echo '<a href="'.$baseUrl.'&page='.$i.'">'.$i.'</a>';
        }
        if ($page < $totalPages) echo '<a href="'.$baseUrl.'&page='.($page+1).'">التالي <i class="fas fa-arrow-left"></i></a>';
        echo '</div>';
    }
}

// Carousel navigation
?>
<script>
function carouselPrev(btn) {
    const carousel = btn.closest('.card-carousel');
    const imgs = carousel.querySelector('.card-carousel-imgs');
    const current = parseInt(imgs.dataset.current || '0');
    const total = imgs.children.length - (carousel.querySelector('.no-image') ? 1 : 0);
    const next = current > 0 ? current - 1 : total - 1;
    imgs.style.transform = 'translateX('+ (next * 100) +'%)';
    imgs.dataset.current = next;
    updateDots(carousel, next);
}
function carouselNext(btn) {
    const carousel = btn.closest('.card-carousel');
    const imgs = carousel.querySelector('.card-carousel-imgs');
    const current = parseInt(imgs.dataset.current || '0');
    const total = imgs.children.length - (carousel.querySelector('.no-image') ? 1 : 0);
    const next = current < total - 1 ? current + 1 : 0;
    imgs.style.transform = 'translateX('+ (next * 100) +'%)';
    imgs.dataset.current = next;
    updateDots(carousel, next);
}
function updateDots(carousel, idx) {
    carousel.querySelectorAll('.carousel-dot').forEach((dot, i) => {
        dot.classList.toggle('active', i === idx);
    });
}
function archiveProduct(id) {
    if (!confirm('هل تريد أرشفة هذا المنتج؟')) return;
    const fd = new FormData();
    fd.append('csrf', csrfToken);
    fd.append('id', id);
    fetch('?action=archive', { method:'POST', body: fd }).then(() => location.reload());
}
function deleteProduct(id) {
    if (!confirm('هل تريد حذف هذا المنتج نهائياً؟')) return;
    const fd = new FormData();
    fd.append('csrf', csrfToken);
    fd.append('id', id);
    fetch('?action=delete', { method:'POST', body: fd }).then(() => location.reload());
}
</script>
<?php

// ─── HOME ──────────────────────────────────────────────────
function showHome(int $page): void {
    $d = db();
    $allProducts = allProducts($d, false);
    $groups = [];
    foreach ($allProducts as $p) {
        $key = $p['group'];
        $groups[$key] = ($groups[$key] ?? 0) + 1;
    }
    arsort($groups);
    
    $statusFilter = $_GET['status'] ?? '';
    $filtered = $statusFilter ? array_filter($allProducts, fn($p) => $p['status'] === $statusFilter) : $allProducts;
    $filtered = array_values($filtered);
    
    $sortBy = $_GET['sort'] ?? 'id';
    if ($sortBy === 'code') usort($filtered, fn($a,$b) => strcmp($a['code'], $b['code']));
    elseif ($sortBy === 'status') usort($filtered, fn($a,$b) => strcmp($a['status'], $b['status']));
    
    $perPage = 12;
    $total = count($filtered);
    $totalPages = max(1, (int)ceil($total / $perPage));
    $page = max(1, min($page, $totalPages));
    $paged = array_slice($filtered, ($page-1)*$perPage, $perPage);
    
    ob_start();
    ?>
    <div class="toolbar">
        <div class="toolbar-title">تصفح كل المنتجات</div>
        <div class="toolbar-count"><?= $total ?> منتج</div>
        <select class="toolbar-select" onchange="location='?action=home&status='+this.value+'&sort=<?= h($sortBy) ?>'">
            <option value="">كل الحالات</option>
            <?php foreach (STATUSES as $s): ?>
            <option value="<?= h($s) ?>" <?= $statusFilter===$s?'selected':'' ?>><?= h($s) ?></option>
            <?php endforeach; ?>
        </select>
        <select class="toolbar-select" onchange="location='?action=home&status=<?= h($statusFilter) ?>&sort='+this.value">
            <option value="id" <?= $sortBy==='id'?'selected':'' ?>>الأحدث</option>
            <option value="code" <?= $sortBy==='code'?'selected':'' ?>>حسب الكود</option>
            <option value="status" <?= $sortBy==='status'?'selected':'' ?>>حسب الحالة</option>
        </select>
    </div>
    
    <div class="filter-tabs">
        <?php foreach (['الكل'=>'','Running'=>'Running','New Model'=>'New+Model','Slow Moving'=>'Slow+Moving','Aging'=>'Aging'] as $label=>$val): ?>
        <a href="?action=home&status=<?= urlencode($val) ?>" class="filter-tab <?= $statusFilter===$val?'active':'' ?>"><?= $label ?></a>
        <?php endforeach; ?>
    </div>
    
    <?php renderProducts($paged, $page, $totalPages, '?action=home&status='.urlencode($statusFilter).'&sort='.urlencode($sortBy)); ?>
    <?php
    
    layout('الرئيسية', ob_get_clean(), $groups, '', (string)($action ?? 'home'));
}

// ─── GROUP VIEW ────────────────────────────────────────────
function showGroup(string $prefix, int $page): void {
    $d = db();
    $allProducts = allProducts($d, false);
    $filtered = array_values(array_filter($allProducts, fn($p) => $p['group'] === $prefix));
    
    $statusFilter = $_GET['status'] ?? '';
    if ($statusFilter) $filtered = array_values(array_filter($filtered, fn($p) => $p['status'] === $statusFilter));
    
    $perPage = 12;
    $total = count($filtered);
    $totalPages = max(1, (int)ceil($total / $perPage));
    $page = max(1, min($page, $totalPages));
    $paged = array_slice($filtered, ($page-1)*$perPage, $perPage);
    
    ob_start();
    ?>
    <div class="toolbar">
        <div class="toolbar-title">مجموعة الكود: <?= h($prefix) ?></div>
        <div class="toolbar-count"><?= $total ?> منتج</div>
    </div>
    
    <div class="filter-tabs">
        <?php foreach (['الكل'=>'','Running'=>'Running','New Model'=>'New+Model','Slow Moving'=>'Slow+Moving','Aging'=>'Aging'] as $label=>$val): ?>
        <a href="?action=group&prefix=<?= urlencode((string)$prefix) ?>&status=<?= urlencode($val) ?>" class="filter-tab <?= $statusFilter===$val?'active':'' ?>"><?= $label ?></a>
        <?php endforeach; ?>
    </div>
    
    <?php renderProducts($paged, $page, $totalPages, '?action=group&prefix='.urlencode((string)$prefix).'&status='.urlencode($statusFilter)); ?>
    <?php
    
    $groups = getGroups($d);
    layout('مجموعة '.$prefix, ob_get_clean(), $groups, '', (string)($action ?? 'home'));
}

// ─── SEARCH ────────────────────────────────────────────────
function showSearch(string $q, int $page): void {
    $d = db();
    $q = trim($q);
    $allProducts = allProducts($d, false);
    $filtered = $q === '' ? $allProducts : array_values(array_filter($allProducts, function($p) use ($q) {
        return stripos($p['code'],$q)!==false || stripos($p['description'],$q)!==false || stripos($p['brand'],$q)!==false;
    }));
    
    $perPage = 12;
    $total = count($filtered);
    $totalPages = max(1, (int)ceil($total / $perPage));
    $page = max(1, min($page, $totalPages));
    $paged = array_slice($filtered, ($page-1)*$perPage, $perPage);
    
    ob_start();
    ?>
    <div class="toolbar">
        <div class="toolbar-title"><i class="fas fa-search"></i> نتائج البحث</div>
        <div class="toolbar-count"><?= $total ?> نتيجة عن "<?= h($q) ?>"</div>
    </div>
    <?php renderProducts($paged, $page, $totalPages, '?action=search&q='.urlencode((string)$q)); ?>
    <?php
    
    $groups = getGroups($d);
    layout('بحث: '.$q, ob_get_clean(), $groups, '', (string)($action ?? 'home'));
}

// ─── ERROR PAGES ───────────────────────────────────────────
function showError(string $message): void {
    ob_start();
    ?><div class="error-box"><h3><i class="fas fa-exclamation-circle"></i> حدث خطأ</h3><p><?= h($message) ?></p><a href="?action=home" class="btn btn-outline" style="margin-top:16px;">العودة للرئيسية</a></div><?php
    layout('خطأ', ob_get_clean(), [], '', 'home');
}

function showSetupError(string $message): void {
    ob_start();
    ?>
    <div class="error-box">
        <h3><i class="fas fa-exclamation-triangle"></i> تعذر الاتصال بقاعدة البيانات</h3>
        <p style="margin:12px 0;"><?= h($message) ?></p>
        <div style="text-align:right;margin-top:20px;padding:16px;background:rgba(0,0,0,0.05);border-radius:8px;">
            <h4 style="margin-bottom:10px;">خطوات الحل:</h4>
            <ol style="padding-right:20px;line-height:2;">
                <li>افتح <strong>XAMPP Control Panel</strong></li>
                <li>تأكد إن <strong>MySQL شغال</strong> (لو مش شغال اضغط Start)</li>
                <li>بعد ما MySQL يشتغل، حدّث الصفحة (F5)</li>
            </ol>
        </div>
        <div style="margin-top:20px;">
            <a href="setup.php" class="btn btn-primary" style="margin:4px;"><i class="fas fa-stethoscope"></i> فحص النظام</a>
            <a href="?action=home" class="btn btn-outline" style="margin:4px;"><i class="fas fa-sync"></i> تحديث</a>
        </div>
    </div>
    <?php
    layout('خطأ في الإعداد', ob_get_clean(), [], '', 'home');
}
