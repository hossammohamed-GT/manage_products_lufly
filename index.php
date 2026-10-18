<?php
declare(strict_types=1);
require __DIR__.'/app.php';

// ─── Routing ─────────────────────────────────────────────
$action = $_GET['action'] ?? 'home';
$page = max(1, (int)($_GET['page'] ?? 1));

try {
    // Auto-install if needed
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

    // Inline save endpoint (JSON)
    if ($action === 'inline_save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            checkCsrf();
            $d = db();
            $id = (int)($_POST['id'] ?? 0);
            $field = (string)($_POST['field'] ?? '');
            $value = trim((string)($_POST['value'] ?? ''));
            $allowed = ['code','brand','description','notes','status'];
            if (!in_array($field, $allowed, true)) throw new RuntimeException('حقل غير صالح');
            if ($field === 'status' && !in_array($value, STATUSES, true)) throw new RuntimeException('حالة غير صالحة');
            if (strlen($value) > 60000) throw new RuntimeException('النص طويل جداً');
            $now = date('c');
            $s = run($d, "UPDATE products SET $field=?, version=version+1, updated_at=? WHERE id=? AND archived=0", [$value, $now, $id]);
            if ($s->rowCount() !== 1) throw new RuntimeException('المنتج غير موجود أو تم تعديله');
            echo json_encode(['ok'=>true, 'field'=>$field, 'value'=>$value]);
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
        case 'view': showProduct((int)($_GET['id'] ?? 0)); break;
        case 'edit': editProduct((int)($_GET['id'] ?? 0)); break;
        case 'save':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                checkCsrf();
                $id = saveProduct(db());
                flash('تم حفظ المنتج بنجاح ✓');
                go('?action=view&id=' . $id);
            }
            break;
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
                if ($id) { run(db(), 'UPDATE products SET archived=0 WHERE id=?', [$id]); flash('تم الاستعادة ✓'); }
                go('?action=archive_list');
            }
            break;
        case 'archive_list': showArchive($page); break;
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
        case 'settings': showSettings(); break;
        case 'save_settings':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                checkCsrf();
                $d = db();
                setSetting($d, 'site_title', trim($_POST['site_title'] ?? 'LUFLY Catalog'));
                $primary = trim($_POST['primary_color_text'] ?? ($_POST['primary_color'] ?? '#1F9E9E'));
                $secondary = trim($_POST['secondary_color_text'] ?? ($_POST['secondary_color'] ?? '#147070'));
                setSetting($d, 'primary_color', $primary);
                setSetting($d, 'secondary_color', $secondary);
                flash('تم حفظ الإعدادات ✓');
                go('?action=settings');
            }
            break;
        case 'reinstall':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                checkCsrf();
                $d = db();
                $d->exec('DROP TABLE IF EXISTS media');
                $d->exec('DROP TABLE IF EXISTS products');
                $d->exec('DROP TABLE IF EXISTS settings');
                setSetting($d, 'installed', '0');
                install();
                flash('تم إعادة التثبيت ✓');
                go('?action=home');
            }
            break;
        case 'setup_error': showSetupError($setupError ?? 'خطأ'); break;
        default: showHome(1);
    }
} catch (Throwable $e) {
    showError($e->getMessage());
}

// ═══════════════════════════════════════════════════════════
// HELPER FUNCTIONS
// ═══════════════════════════════════════════════════════════

function layout(string $title, string $content, array $groups = [], string $extraJs = ''): void
{
    require_once __DIR__.'/includes/sidebar.php';
    $d = db();
    $siteTitle = setting($d, 'site_title', 'LUFLY Catalog');
    $primary = setting($d, 'primary_color', '#1F9E9E');
    $secondary = setting($d, 'secondary_color', '#147070');
    $flash = $_SESSION['flash'] ?? '';
    unset($_SESSION['flash']);
    $sidebarHtml = !empty($groups) ? renderSidebar($groups, (string)($_GET['prefix'] ?? '')) : '';
    $useSidebar = !empty($groups);
    ?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($title) ?> - <?= h($siteTitle) ?></title>
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
.top-nav a { color: var(--text-muted); text-decoration: none; padding: 8px 14px; border-radius: 8px; font-size: 0.9rem; transition: all 0.2s; }
.top-nav a:hover, .top-nav a.active { background: var(--primary-light); color: var(--primary); }

/* ═══ HERO BANNER ═══ */
.hero-banner {
    background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
    padding: 40px 24px;
    color: white;
    position: relative;
    overflow: hidden;
}
.hero-banner::before {
    content: '';
    position: absolute;
    top: -50%;
    left: -10%;
    width: 500px;
    height: 500px;
    background: rgba(255,255,255,0.06);
    border-radius: 50%;
}
.hero-banner::after {
    content: '';
    position: absolute;
    bottom: -60%;
    right: -5%;
    width: 400px;
    height: 400px;
    background: rgba(255,255,255,0.04);
    border-radius: 50%;
}
.hero-inner {
    max-width: 1400px;
    margin: 0 auto;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 32px;
    position: relative;
    z-index: 1;
}
.hero-text h1 { font-size: 2.2rem; font-weight: 800; margin-bottom: 8px; }
.hero-text h1 span { display: block; font-size: 1.1rem; font-weight: 500; opacity: 0.9; margin-top: 8px; }
.hero-stats { display: flex; gap: 32px; margin-top: 20px; }
.hero-stat { text-align: center; }
.hero-stat-num { font-size: 1.8rem; font-weight: 800; }
.hero-stat-label { font-size: 0.8rem; opacity: 0.85; }
.hero-image {
    width: 320px;
    height: 180px;
    background: rgba(255,255,255,0.1);
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 5rem;
    flex-shrink: 0;
}

/* ══ MAIN LAYOUT ═══ */
.main-layout {
    display: flex;
    max-width: 1400px;
    margin: 0 auto;
    gap: 24px;
    padding: 24px;
}
.main-content { flex: 1; min-width: 0; }

/* ═══ TOOLBAR ═══ */
.toolbar {
    background: white;
    border-radius: var(--radius);
    padding: 16px 20px;
    box-shadow: var(--shadow);
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}
.toolbar-title { font-weight: 700; font-size: 1.1rem; color: var(--text); }
.toolbar-count { color: var(--text-muted); font-size: 0.9rem; }
.toolbar-search {
    flex: 1;
    min-width: 200px;
    padding: 10px 16px;
    border: 2px solid var(--border);
    border-radius: 10px;
    font-size: 0.9rem;
    outline: none;
    direction: rtl;
    transition: border-color 0.2s;
}
.toolbar-search:focus { border-color: var(--primary); }
.toolbar-select {
    padding: 10px 14px;
    border: 2px solid var(--border);
    border-radius: 10px;
    font-size: 0.9rem;
    background: white;
    outline: none;
    cursor: pointer;
}
.toolbar-select:focus { border-color: var(--primary); }

/* ═══ FLASH ═══ */
.flash { background: var(--success); color: white; padding: 12px 18px; border-radius: 10px; margin-bottom: 16px; font-weight: 600; font-size: 0.95rem; }

/* ═══ PRODUCTS GRID ═══ */
.products-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(290px, 1fr)); gap: 20px; }
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
.product-card-header { position: relative; height: 180px; background: #f0f2f5; overflow: hidden; }
.product-card-header img { width: 100%; height: 100%; object-fit: cover; }
.product-card-header .no-img { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; color: #bbb; font-size: 3rem; }
.status-tag {
    position: absolute;
    top: 12px;
    right: 12px;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.72rem;
    font-weight: 700;
    color: white;
}
.status-tag-Running { background: var(--success); }
.status-tag-New-Model { background: #3b82f6; }
.status-tag-Slow-Moving { background: var(--warning); }
.status-tag-Aging { background: var(--danger); }
.media-badge {
    position: absolute;
    bottom: 12px;
    left: 12px;
    background: rgba(0,0,0,0.7);
    color: white;
    padding: 4px 10px;
    border-radius: 8px;
    font-size: 0.75rem;
    font-weight: 600;
}
.product-card-body { padding: 16px; flex: 1; display: flex; flex-direction: column; }
.product-code {
    font-family: 'Consolas', monospace;
    font-weight: 700;
    font-size: 1.05rem;
    color: var(--primary);
    margin-bottom: 6px;
    cursor: pointer;
    padding: 4px 6px;
    margin: -4px -6px 4px;
    border-radius: 6px;
    transition: background 0.15s;
    border: 1px solid transparent;
}
.product-code:hover { background: var(--primary-light); border-color: var(--primary); }
.product-desc {
    font-size: 0.85rem;
    color: var(--text-muted);
    line-height: 1.5;
    flex: 1;
    cursor: pointer;
    padding: 4px 6px;
    margin: -4px -6px 4px;
    border-radius: 6px;
    border: 1px solid transparent;
    transition: background 0.15s;
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.product-desc:hover { background: #f8f9fa; border-color: var(--border); }
.product-desc.expanded { -webkit-line-clamp: unset; }
.product-card-footer {
    padding: 12px 16px;
    border-top: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 8px;
}
.product-brand { font-size: 0.8rem; color: var(--text-muted); cursor: pointer; padding: 2px 6px; border-radius: 4px; }
.product-brand:hover { background: #f0f2f5; }
.card-actions { display: flex; gap: 4px; }
.card-btn {
    width: 32px;
    height: 32px;
    border: none;
    background: #f0f2f5;
    border-radius: 8px;
    cursor: pointer;
    font-size: 0.9rem;
    transition: all 0.15s;
    display: flex;
    align-items: center;
    justify-content: center;
}
.card-btn:hover { background: var(--primary); color: white; }
.card-btn.danger:hover { background: var(--danger); }

/* ═══ INLINE EDITING ═══ */
.inline-edit { position: relative; }
.inline-edit input, .inline-edit textarea, .inline-edit select {
    width: 100%;
    padding: 6px 10px;
    border: 2px solid var(--primary);
    border-radius: 8px;
    font-size: inherit;
    font-family: inherit;
    outline: none;
    background: white;
    direction: rtl;
}
.inline-edit textarea { min-height: 80px; resize: vertical; }
.inline-edit .inline-actions { display: flex; gap: 4px; margin-top: 4px; }
.inline-edit .inline-actions button {
    padding: 4px 10px;
    border: none;
    border-radius: 6px;
    font-size: 0.8rem;
    cursor: pointer;
    font-weight: 600;
}
.inline-save-btn { background: var(--primary); color: white; }
.inline-cancel-btn { background: #f0f2f5; color: var(--text-muted); }
.inline-saving { opacity: 0.6; pointer-events: none; }
.inline-saving::after { content: ' 💾'; }

/* ═══ NO RESULTS ═══ */
.no-results { text-align: center; padding: 60px 20px; color: var(--text-muted); }
.no-results .icon { font-size: 4rem; margin-bottom: 16px; }
.no-results h3 { font-size: 1.2rem; margin-bottom: 8px; }

/* ═══ PAGINATION ═══ */
.pagination { display: flex; gap: 6px; justify-content: center; margin-top: 32px; flex-wrap: wrap; }
.pagination a, .pagination span { padding: 8px 14px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 0.9rem; transition: all 0.2s; }
.pagination a { background: white; color: var(--text); box-shadow: var(--shadow); }
.pagination a:hover { background: var(--primary); color: white; }
.pagination .current { background: var(--primary); color: white; }

/* ═══ PRODUCT DETAIL ══ */
.detail-layout { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
@media (max-width: 900px) { .detail-layout { grid-template-columns: 1fr; } }
.detail-box { background: white; border-radius: var(--radius); padding: 24px; box-shadow: var(--shadow); }
.gallery-img { width: 100%; aspect-ratio: 1; object-fit: contain; border-radius: 10px; background: #f8f9fa; margin-bottom: 12px; }
.gallery-thumbs { display: flex; gap: 8px; flex-wrap: wrap; }
.gallery-thumb { width: 60px; height: 60px; object-fit: cover; border-radius: 8px; cursor: pointer; border: 2px solid transparent; }
.gallery-thumb:hover, .gallery-thumb.active { border-color: var(--primary); }
.detail-row { padding: 14px 0; border-bottom: 1px solid var(--border); }
.detail-row:last-child { border-bottom: none; }
.detail-label { font-weight: 600; color: var(--text-muted); font-size: 0.85rem; margin-bottom: 4px; }
.detail-value { font-size: 1rem; }
.detail-value.code-val { font-family: 'Consolas', monospace; font-size: 1.3rem; font-weight: 700; color: var(--primary); }

/* ═══ FORM ═══ */
.form-card { background: white; border-radius: var(--radius); padding: 24px; box-shadow: var(--shadow); }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
@media (max-width: 700px) { .form-grid { grid-template-columns: 1fr; } }
.form-group { margin-bottom: 18px; }
.form-label { display: block; font-weight: 600; margin-bottom: 6px; font-size: 0.9rem; }
.form-input, .form-textarea, .form-select { width: 100%; padding: 11px 14px; border: 2px solid var(--border); border-radius: 10px; font-size: 0.95rem; outline: none; direction: rtl; transition: border-color 0.2s; }
.form-input:focus, .form-textarea:focus, .form-select:focus { border-color: var(--primary); }
.form-textarea { min-height: 100px; resize: vertical; }

/* ═══ BUTTONS ══ */
.btn { display: inline-flex; align-items: center; gap: 6px; padding: 10px 18px; border-radius: 10px; font-size: 0.9rem; font-weight: 600; cursor: pointer; border: none; transition: all 0.2s; text-decoration: none; }
.btn-primary { background: var(--primary); color: white; }
.btn-primary:hover { background: var(--primary-dark); }
.btn-success { background: var(--success); color: white; }
.btn-danger { background: var(--danger); color: white; }
.btn-outline { background: transparent; color: var(--primary); border: 2px solid var(--primary); }
.btn-outline:hover { background: var(--primary-light); }
.btn-sm { padding: 6px 12px; font-size: 0.82rem; }

/* ═══ BACK LINK ═══ */
.back-link { display: inline-flex; align-items: center; gap: 6px; color: var(--primary); text-decoration: none; font-weight: 600; margin-bottom: 16px; font-size: 0.9rem; }
.back-link:hover { text-decoration: underline; }

/* ══ SECTION HEADER ═══ */
.section-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 12px; }
.section-title { font-size: 1.4rem; font-weight: 700; }

/* ═══ FILTER TABS ═══ */
.filter-tabs { display: flex; gap: 8px; margin-bottom: 20px; flex-wrap: wrap; }
.filter-tab { padding: 7px 14px; border-radius: 20px; text-decoration: none; font-size: 0.82rem; font-weight: 600; background: white; color: var(--text); box-shadow: var(--shadow); transition: all 0.2s; }
.filter-tab:hover, .filter-tab.active { background: var(--primary); color: white; }

/* ═══ MEDIA LIST ═══ */
.media-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 10px; margin-top: 12px; }
.media-item { border-radius: 8px; overflow: hidden; background: #f8f9fa; border: 2px solid var(--border); }
.media-item img { width: 100%; height: 110px; object-fit: cover; }
.media-item-actions { padding: 6px; display: flex; gap: 4px; flex-wrap: wrap; }
.media-item-actions select, .media-item-actions input { font-size: 0.72rem; padding: 3px; border: 1px solid var(--border); border-radius: 4px; }

/* ═══ ERROR BOX ═══ */
.error-box { background: #fee2e2; border: 2px solid #fca5a5; border-radius: var(--radius); padding: 28px; text-align: center; color: #991b1b; max-width: 600px; margin: 40px auto; }
.error-box h3 { margin-bottom: 12px; font-size: 1.3rem; }

/* ═══ RESPONSIVE ═══ */
@media (max-width: 700px) {
    .hero-inner { flex-direction: column; text-align: center; }
    .hero-text h1 { font-size: 1.5rem; }
    .hero-stats { justify-content: center; }
    .hero-image { width: 240px; height: 140px; }
    .toolbar { flex-direction: column; align-items: stretch; }
    .products-grid { grid-template-columns: 1fr; }
    .top-header { flex-direction: column; gap: 8px; }
}

/* ═══ HINT TOOLTIP ═══ */
.edit-hint {
    position: absolute;
    top: -8px;
    right: -8px;
    background: var(--primary);
    color: white;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    font-size: 0.7rem;
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    transition: opacity 0.2s;
    pointer-events: none;
}
.product-code:hover .edit-hint, .product-desc:hover .edit-hint, .product-brand:hover .edit-hint { opacity: 1; }

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
        <a href="?action=home" class="<?= $action==='home'?'active':'' ?>">🏠 الرئيسية</a>
        <a href="?action=archive_list" class="<?= $action==='archive_list'?'active':'' ?>">📦 الأرشيف</a>
        <a href="?action=settings" class="<?= $action==='settings'?'active':'' ?>">⚙️ الإعدادات</a>
    </nav>
</div>

<!-- ═══ HERO BANNER ═══ -->
<?php if ($action === 'home' || $action === 'group' || $action === 'search'): ?>
<div class="hero-banner">
    <div class="hero-inner">
        <div class="hero-text">
            <h1>
                <?= $action === 'home' ? 'كتالوج منتجات LUFLY' : ($action === 'group' ? 'مجموعة: ' . h((string)($_GET['prefix'] ?? '')) : 'نتائج البحث') ?>
                <span>
                    <?= $action === 'home' ? 'إدارة المنتجات بالصور والبيانات — اضغط على أي حقل لتعديله مباشرة' : 'اضغط على أي منتج لعرض التفاصيل' ?>
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
        <div class="hero-image"></div>
    </div>
</div>
<?php endif; ?>

<!-- ═══ MAIN LAYOUT ═══ -->
<?php if ($useSidebar): ?>
<div class="main-layout">
    <?= $sidebarHtml ?>
    <div class="main-content">
        <?php if ($flash): ?><div class="flash"><?= h($flash) ?></div><?php endif; ?>
        <?= $content ?>
    </div>
</div>
<?php else: ?>
<div style="max-width:1400px; margin:0 auto; padding:24px;">
    <?php if ($flash): ?><div class="flash"><?= h($flash) ?></div><?php endif; ?>
    <?= $content ?>
</div>
<?php endif; ?>

<!-- ═══ INLINE EDITING JS ═══ -->
<?= $extraJs ?>
<script>
(function(){
    const csrf = document.querySelector('meta[name="csrf"]')?.content || '';
    // Get CSRF from page (we'll store it in a hidden input)
    const csrfEl = document.getElementById('csrf-token');
    const token = csrfEl ? csrfEl.value : '';

    window.inlineEdit = function(el, field, productId, productVersion) {
        // Prevent double-init
        if (el.querySelector('.inline-edit')) return;
        
        const currentText = el.dataset.value || el.textContent.trim();
        const isTextarea = field === 'description' || field === 'notes';
        const isSelect = field === 'status';
        const isCode = field === 'code';
        
        const wrap = document.createElement('div');
        wrap.className = 'inline-edit';
        
        let input;
        if (isSelect) {
            input = document.createElement('select');
            input.className = 'form-select';
            ['Running','New Model','Slow Moving','Aging'].forEach(s => {
                const opt = document.createElement('option');
                opt.value = s; opt.textContent = s;
                if (s === currentText) opt.selected = true;
                input.appendChild(opt);
            });
        } else if (isTextarea) {
            input = document.createElement('textarea');
            input.className = 'form-textarea';
            input.value = currentText;
        } else {
            input = document.createElement('input');
            input.type = 'text';
            input.className = 'form-input';
            input.value = currentText;
        }
        
        const actions = document.createElement('div');
        actions.className = 'inline-actions';
        const saveBtn = document.createElement('button');
        saveBtn.className = 'inline-save-btn';
        saveBtn.textContent = '💾 حفظ';
        const cancelBtn = document.createElement('button');
        cancelBtn.className = 'inline-cancel-btn';
        cancelBtn.textContent = ' إلغاء';
        actions.appendChild(saveBtn);
        actions.appendChild(cancelBtn);
        
        wrap.appendChild(input);
        wrap.appendChild(actions);
        
        const originalHTML = el.innerHTML;
        el.innerHTML = '';
        el.appendChild(wrap);
        el.classList.add('inline-active');
        input.focus();
        if (!isTextarea && !isSelect) input.select();
        
        function save() {
            const val = input.value.trim();
            if (val === currentText) { cancel(); return; }
            wrap.classList.add('inline-saving');
            saveBtn.disabled = true;
            
            const fd = new FormData();
            fd.append('csrf', token);
            fd.append('id', productId);
            fd.append('field', field);
            fd.append('value', val);
            
            fetch('?action=inline_save', { method:'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.ok) {
                    el.dataset.value = data.value;
                    el.innerHTML = data.value + '<span class="edit-hint">✏️</span>';
                    el.classList.remove('inline-active');
                    // Flash success
                    showToast('✅ تم الحفظ');
                } else {
                    showToast('❌ ' + (data.error || 'خطأ في الحفظ'), true);
                    cancel();
                }
            })
            .catch(err => {
                showToast('❌ خطأ في الاتصال', true);
                cancel();
            });
        }
        
        function cancel() {
            el.innerHTML = originalHTML;
            el.classList.remove('inline-active');
        }
        
        saveBtn.onclick = save;
        cancelBtn.onclick = cancel;
        input.onkeydown = function(e) {
            if (e.key === 'Enter' && !isTextarea) { e.preventDefault(); save(); }
            if (e.key === 'Escape') { e.preventDefault(); cancel(); }
        };
        input.onblur = function() { setTimeout(() => { if (el.querySelector('.inline-edit')) save(); }, 150); };
    };
    
    function showToast(msg, isError) {
        let t = document.getElementById('toast');
        if (!t) {
            t = document.createElement('div');
            t.id = 'toast';
            t.style.cssText = 'position:fixed;bottom:24px;left:50%;transform:translateX(-50%);padding:12px 24px;border-radius:10px;color:white;font-weight:600;z-index:9999;transition:opacity 0.3s;box-shadow:0 4px 12px rgba(0,0,0,0.2);';
            document.body.appendChild(t);
        }
        t.textContent = msg;
        t.style.background = isError ? '#ef4444' : '#10b981';
        t.style.opacity = '1';
        setTimeout(() => { t.style.opacity = '0'; }, 2000);
    }
})();
</script>
<meta name="csrf" content="<?= h(csrf()) ?>">
<input type="hidden" id="csrf-token" value="<?= h(csrf()) ?>">
</body>
</html>
    <?php
}

// ═══════════════════════════════════════════════════════════
// PAGE FUNCTIONS
// ═══════════════════════════════════════════════════════════

function productCardHTML(array $p): string {
    $cover = !empty($p['cover']) ? '<img src="'.h($p['cover']).'" alt="" loading="lazy">' : '<div class="no-img">📷</div>';
    $statusClass = 'status-tag-' . str_replace(' ', '-', $p['status']);
    $statusText = ['Running'=>'نشط','New Model'=>'موديل جديد','Slow Moving'=>'بطيء','Aging'=>'قديم'][$p['status']] ?? $p['status'];
    $badges = '';
    if (!empty($p['duplicate'])) $badges .= '<span class="badge-duplicate" style="position:absolute;top:12px;left:12px;background:#fef3c7;color:#92400e;padding:3px 8px;border-radius:12px;font-size:0.7rem;font-weight:700;">⚠ مكرر</span>';
    
    return '<div class="product-card" data-id="'.$p['id'].'" data-version="'.$p['version'].'">'
        .'<div class="product-card-header">'
        .$cover
        .'<span class="status-tag '.$statusClass.'">'.$statusText.'</span>'
        .$badges
        .'<span class="media-badge">📎 ' . (int)$p['media_count'] . '</span>'
        .'</div>'
        .'<div class="product-card-body">'
        .'<div class="product-code" data-value="'.h($p['code']).'" onclick="inlineEdit(this,\'code\','.$p['id'].','.$p['version'].')" title="اضغط للتعديل">'
        .h($p['code']).'<span class="edit-hint">✏️</span>'
        .'</div>'
        .'<div class="product-desc" data-value="'.h($p['description']).'" onclick="inlineEdit(this,\'description\','.$p['id'].','.$p['version'].')" title="اضغط للتعديل">'
        .h($p['description'] ?: '— اضغط لإضافة وصف —')
        .'</div>'
        .'</div>'
        .'<div class="product-card-footer">'
        .'<span class="product-brand" data-value="'.h($p['brand']).'" onclick="inlineEdit(this,\'brand\','.$p['id'].','.$p['version'].')" title="اضغط للتعديل">️ '.h($p['brand'] ?: '—').'</span>'
        .'<div class="card-actions">'
        .'<a href="?action=view&id='.$p['id'].'" class="card-btn" title="عرض">👁</a>'
        .'<a href="?action=edit&id='.$p['id'].'" class="card-btn" title="تعديل كامل">✏️</a>'
        .'</div>'
        .'</div>'
        .'</div>';
}

function renderProducts(array $products, int $page, int $totalPages, string $baseUrl): void {
    if (empty($products)) {
        echo '<div class="no-results"><div class="icon">📭</div><h3>لا توجد منتجات</h3><p>جرب البحث بكلمات أخرى أو اختر مجموعة مختلفة</p></div>';
        return;
    }
    echo '<div class="products-grid">';
    foreach ($products as $p) echo productCardHTML($p);
    echo '</div>';
    
    if ($totalPages > 1) {
        echo '<div class="pagination">';
        if ($page > 1) echo '<a href="'.$baseUrl.'&page='.($page-1).'">→ السابق</a>';
        $start = max(1, $page-3); $end = min($totalPages, $page+3);
        for ($i = $start; $i <= $end; $i++) {
            if ($i === $page) echo '<span class="current">'.$i.'</span>';
            else echo '<a href="'.$baseUrl.'&page='.$i.'">'.$i.'</a>';
        }
        if ($page < $totalPages) echo '<a href="'.$baseUrl.'&page='.($page+1).'">التالي ←</a>';
        echo '</div>';
    }
}

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
    
    // Filter toolbar
    $statusFilter = $_GET['status'] ?? '';
    $filtered = $statusFilter ? array_filter($allProducts, fn($p) => $p['status'] === $statusFilter) : $allProducts;
    $filtered = array_values($filtered);
    
    // Sort
    $sortBy = $_GET['sort'] ?? 'id';
    if ($sortBy === 'code') usort($filtered, fn($a,$b) => strcmp($a['code'], $b['code']));
    elseif ($sortBy === 'status') usort($filtered, fn($a,$b) => strcmp($a['status'], $b['status']));
    
    $perPage = 18;
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
    
    <?php renderProducts($paged, $page, $totalPages, '?action=home&status='.urlencode($statusFilter).'&sort='.urlencode($sortBy)); ?>
    <?php
    
    layout('الرئيسية', ob_get_clean(), $groups);
}

// ─── GROUP VIEW ────────────────────────────────────────────
function showGroup(string $prefix, int $page): void {
    $d = db();
    $allProducts = allProducts($d, false);
    $filtered = array_values(array_filter($allProducts, fn($p) => $p['group'] === $prefix));
    
    $statusFilter = $_GET['status'] ?? '';
    if ($statusFilter) $filtered = array_values(array_filter($filtered, fn($p) => $p['status'] === $statusFilter));
    
    $perPage = 18;
    $total = count($filtered);
    $totalPages = max(1, (int)ceil($total / $perPage));
    $page = max(1, min($page, $totalPages));
    $paged = array_slice($filtered, ($page-1)*$perPage, $perPage);
    
    ob_start();
    ?>
    <div class="toolbar">
        <div class="toolbar-title">مجموعة الكود: <?= h($prefix) ?></div>
        <div class="toolbar-count"><?= $total ?> منتج</div>
        <select class="toolbar-select" onchange="location='?action=group&prefix=<?= urlencode((string)$prefix) ?>&status='+this.value">
            <option value="">كل الحالات</option>
            <?php foreach (STATUSES as $s): ?>
            <option value="<?= h($s) ?>" <?= $statusFilter===$s?'selected':'' ?>><?= h($s) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    
    <div class="filter-tabs">
        <?php foreach (['الكل'=>'','Running'=>'Running','New Model'=>'New+Model','Slow Moving'=>'Slow+Moving','Aging'=>'Aging'] as $label=>$val): ?>
        <a href="?action=group&prefix=<?= urlencode((string)$prefix) ?>&status=<?= urlencode($val) ?>" class="filter-tab <?= $statusFilter===$val?'active':'' ?>"><?= $label ?></a>
        <?php endforeach; ?>
    </div>
    
    <?php renderProducts($paged, $page, $totalPages, '?action=group&prefix='.urlencode((string)$prefix).'&status='.urlencode($statusFilter)); ?>
    <?php
    
    $groups = getGroups($d);
    layout('مجموعة '.$prefix, ob_get_clean(), $groups);
}

// ─── SEARCH ────────────────────────────────────────────────
function showSearch(string $q, int $page): void {
    $d = db();
    $q = trim($q);
    $allProducts = allProducts($d, false);
    $filtered = $q === '' ? $allProducts : array_values(array_filter($allProducts, function($p) use ($q) {
        return stripos($p['code'],$q)!==false || stripos($p['description'],$q)!==false || stripos($p['brand'],$q)!==false;
    }));
    
    $perPage = 18;
    $total = count($filtered);
    $totalPages = max(1, (int)ceil($total / $perPage));
    $page = max(1, min($page, $totalPages));
    $paged = array_slice($filtered, ($page-1)*$perPage, $perPage);
    
    ob_start();
    ?>
    <div class="toolbar">
        <div class="toolbar-title">🔍 نتائج البحث</div>
        <div class="toolbar-count"><?= $total ?> نتيجة عن "<?= h($q) ?>"</div>
    </div>
    <?php renderProducts($paged, $page, $totalPages, '?action=search&q='.urlencode((string)$q)); ?>
    <?php
    
    $groups = getGroups($d);
    layout('بحث: '.$q, ob_get_clean(), $groups);
}

// ─── VIEW PRODUCT ──────────────────────────────────────────
function showProduct(int $id): void {
    $d = db();
    $product = run($d, 'SELECT * FROM products WHERE id=?', [$id])->fetch();
    if (!$product) { showError('المنتج غير موجود'); return; }
    $media = mediaFor($d, $id);
    $group = groupCode($product['code']);
    
    ob_start();
    ?>
    <a href="?action=group&prefix=<?= urlencode((string)$group) ?>" class="back-link">→ العودة لمجموعة <?= h($group) ?></a>
    
    <div class="section-header">
        <div class="section-title"><?= h($product['code']) ?></div>
        <div style="display:flex;gap:8px;">
            <a href="?action=edit&id=<?= $id ?>" class="btn btn-primary">✏️ تعديل كامل</a>
            <form method="post" action="?action=archive" style="display:inline;">
                <?= token() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <button type="submit" class="btn btn-outline btn-sm"> أرشفة</button>
            </form>
        </div>
    </div>
    
    <div class="detail-layout">
        <div class="detail-box">
            <?php if (!empty($media)):
                $previewable = array_values(array_filter($media, fn($m)=>(int)$m['previewable']));
                $first = $previewable[0] ?? null;
                if ($first): ?>
                <img src="<?= h($first['path']) ?>" class="gallery-img" id="mainImg">
                <?php if (count($previewable) > 1): ?>
                <div class="gallery-thumbs">
                    <?php foreach ($previewable as $m): ?>
                    <img src="<?= h($m['path']) ?>" class="gallery-thumb" onclick="document.getElementById('mainImg').src='<?= h($m['path']) ?>'">
                    <?php endforeach; ?>
                </div>
                <?php endif;
                endif;
            else: ?>
                <div class="no-results" style="padding:40px;"><div class="icon">📷</div><p>لا توجد صور</p><a href="?action=edit&id=<?= $id ?>" class="btn btn-outline btn-sm" style="margin-top:12px;">إضافة صور</a></div>
            <?php endif; ?>
            
            <?php $nonPrev = array_filter($media, fn($m)=>!(int)$m['previewable']);
            if (!empty($nonPrev)): ?>
            <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--border);">
                <strong>مرفقات أخرى:</strong>
                <?php foreach ($nonPrev as $m): ?>
                <div style="margin-top:8px;"><a href="<?= h($m['path']) ?>" download class="btn btn-outline btn-sm"> <?= h($m['original_name']?:'تحميل') ?></a></div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        
        <div class="detail-box">
            <div class="detail-row"><div class="detail-label">الكود (اضغط للتعديل)</div><div class="detail-value code-val" data-value="<?= h($product['code']) ?>" onclick="inlineEdit(this,'code',<?= $id ?>,<?= $product['version'] ?>)"><?= h($product['code']) ?><span class="edit-hint">✏️</span></div></div>
            <div class="detail-row"><div class="detail-label">الماركة (اضغط للتعديل)</div><div class="detail-value" data-value="<?= h($product['brand']) ?>" onclick="inlineEdit(this,'brand',<?= $id ?>,<?= $product['version'] ?>)"><?= h($product['brand']?:'—') ?><span class="edit-hint">✏️</span></div></div>
            <div class="detail-row"><div class="detail-label">الحالة (اضغط للتعديل)</div><div class="detail-value" data-value="<?= h($product['status']) ?>" onclick="inlineEdit(this,'status',<?= $id ?>,<?= $product['version'] ?>)"><span class="status-tag status-tag-<?= str_replace(' ','-',$product['status']) ?>" style="position:static;"><?= h($product['status']) ?></span><span class="edit-hint">✏️</span></div></div>
            <div class="detail-row"><div class="detail-label">الوصف (اضغط للتعديل)</div><div class="detail-value" style="white-space:pre-wrap;" data-value="<?= h($product['description']) ?>" onclick="inlineEdit(this,'description',<?= $id ?>,<?= $product['version'] ?>)"><?= h($product['description']?:'—') ?><span class="edit-hint">✏️</span></div></div>
            <?php if ($product['notes']): ?><div class="detail-row"><div class="detail-label">ملاحظات (اضغط للتعديل)</div><div class="detail-value" style="white-space:pre-wrap;" data-value="<?= h($product['notes']) ?>" onclick="inlineEdit(this,'notes',<?= $id ?>,<?= $product['version'] ?>)"><?= h($product['notes']) ?><span class="edit-hint">✏️</span></div></div><?php endif; ?>
            <div class="detail-row"><div class="detail-label">مجموعة الكود</div><div class="detail-value"><a href="?action=group&prefix=<?= urlencode((string)$group) ?>"><?= h($group) ?></a></div></div>
            <div class="detail-row"><div class="detail-label">المرفقات</div><div class="detail-value"><?= count($media) ?> مرفق</div></div>
            <div class="detail-row"><div class="detail-label">الملف المصدر</div><div class="detail-value"><?= h($product['source_file']?:'—') ?> (سطر <?= (int)$product['source_row'] ?>)</div></div>
        </div>
    </div>
    <?php
    
    $groups = getGroups($d);
    layout($product['code'], ob_get_clean(), $groups);
}

// ── EDIT/ADD ──────────────────────────────────────────────
function editProduct(int $id): void {
    $d = db();
    $product = $id ? run($d, 'SELECT * FROM products WHERE id=?', [$id])->fetch() : null;
    if ($id && !$product) { showError('المنتج غير موجود'); return; }
    $media = $id ? mediaFor($d, $id) : [];
    
    ob_start();
    ?>
    <a href="<?= $id?'?action=view&id='.$id:'?action=home' ?>" class="back-link">→ العودة</a>
    <div class="section-header"><h2 class="section-title"><?= $id?'✏️ تعديل: '.h($product['code']):' إضافة منتج جديد' ?></h2></div>
    
    <div class="form-card">
        <form method="post" action="?action=save" enctype="multipart/form-data">
            <?= token() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="version" value="<?= $product['version'] ?? 1 ?>">
            
            <div class="form-grid">
                <div class="form-group"><label class="form-label">الكود *</label><input type="text" name="code" class="form-input" value="<?= h($product['code']??'') ?>" required></div>
                <div class="form-group"><label class="form-label">الماركة</label><input type="text" name="brand" class="form-input" value="<?= h($product['brand']??'lufly') ?>"></div>
            </div>
            
            <div class="form-group"><label class="form-label">الحالة</label><select name="status" class="form-select"><?php foreach (STATUSES as $s): ?><option value="<?= h($s) ?>" <?= ($product['status']??'')===$s?'selected':'' ?>><?= h($s) ?></option><?php endforeach; ?></select></div>
            
            <div class="form-group"><label class="form-label">الوصف</label><textarea name="description" class="form-textarea"><?= h($product['description']??'') ?></textarea></div>
            <div class="form-group"><label class="form-label">ملاحظات</label><textarea name="notes" class="form-textarea" style="min-height:80px;"><?= h($product['notes']??'') ?></textarea></div>
            
            <?php if (!empty($media)): ?>
            <div class="form-group">
                <label class="form-label">المرفقات الحالية</label>
                <div class="media-list">
                    <?php foreach ($media as $m): ?>
                    <div class="media-item">
                        <?php if ((int)$m['previewable']): ?><img src="<?= h($m['path']) ?>"><?php else: ?><div style="height:110px;display:flex;align-items:center;justify-content:center;background:#f0f0f0;">📎</div><?php endif; ?>
                        <div class="media-item-actions">
                            <select name="kind[<?= $m['id'] ?>]"><option value="photo" <?= $m['kind']==='photo'?'selected':'' ?>>صورة</option><option value="drawing" <?= $m['kind']==='drawing'?'selected':'' ?>>رسم</option><option value="extra" <?= $m['kind']==='extra'?'selected':'' ?>>إضافي</option></select>
                            <input type="text" name="caption[<?= $m['id'] ?>]" value="<?= h($m['caption']) ?>" placeholder="وصف" style="width:100%;">
                            <label style="display:flex;align-items:center;gap:4px;color:var(--danger);font-size:0.75rem;"><input type="checkbox" name="remove_media[<?= $m['id'] ?>]" value="1"> حذف</label>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="form-group"><label class="form-label">نوع الصور الجديدة</label><select name="upload_kind" class="form-select" style="max-width:250px;"><option value="photo">صورة المنتج</option><option value="drawing">رسم فني</option><option value="extra">صورة إضافية</option></select></div>
            <div class="form-group"><label class="form-label">رفع صور جديدة</label><input type="file" name="files[]" multiple accept="image/*" class="form-input" style="padding:10px;"><small style="color:var(--text-muted);">JPG, PNG, WebP, GIF — حد أقصى 12MB لكل صورة</small></div>
            
            <div style="display:flex;gap:12px;margin-top:20px;">
                <button type="submit" class="btn btn-success" style="font-size:1rem;padding:12px 28px;">💾 حفظ</button>
                <a href="?action=<?= $id?'view&id='.$id:'home' ?>" class="btn btn-outline">إلغاء</a>
            </div>
        </form>
    </div>
    <?php
    
    layout($id ? 'تعديل: '.$product['code'] : 'إضافة منتج', ob_get_clean());
}

// ─── ARCHIVE LIST ──────────────────────────────────────────
function showArchive(int $page): void {
    $d = db();
    $allProducts = allProducts($d, true);
    $perPage = 18; $total = count($allProducts);
    $totalPages = max(1, (int)ceil($total/$perPage));
    $page = max(1, min($page, $totalPages));
    $paged = array_slice($allProducts, ($page-1)*$perPage, $perPage);
    
    ob_start();
    ?>
    <div class="section-header"><h2 class="section-title"> الأرشيف</h2><span style="color:var(--text-muted)"><?= $total ?> منتج</span></div>
    <?php
    if (empty($paged)) {
        echo '<div class="no-results"><div class="icon"></div><h3>الأرشيف فاضي</h3></div>';
    } else {
        echo '<div class="products-grid">';
        foreach ($paged as $p) {
            echo '<div class="product-card"><div class="product-card-header">'.(!empty($p['cover'])?'<img src="'.h($p['cover']).'">':'<div class="no-img">📷</div>').'</div>';
            echo '<div class="product-card-body"><div class="product-code">'.h($p['code']).'</div><div class="product-desc">'.h(mb_substr($p['description'],0,80)).'</div></div>';
            echo '<div class="product-card-footer"><form method="post" action="?action=restore" style="flex:1;"><input type="hidden" name="id" value="'.$p['id'].'">'.token().'<button type="submit" class="btn btn-primary btn-sm" style="width:100%;">️ استعادة</button></form></div></div>';
        }
        echo '</div>';
        if ($totalPages>1) {
            echo '<div class="pagination">';
            for ($i=1;$i<=$totalPages;$i++) echo $i===$page?'<span class="current">'.$i.'</span>':'<a href="?action=archive_list&page='.$i.'">'.$i.'</a>';
            echo '</div>';
        }
    }
    $groups = getGroups($d);
    layout('الأرشيف', ob_get_clean(), $groups);
}

// ─── SETTINGS ──────────────────────────────────────────────
function showSettings(): void {
    $d = db();
    $totalProducts = (int)$d->query('SELECT COUNT(*) FROM products')->fetchColumn();
    $totalMedia = (int)$d->query('SELECT COUNT(*) FROM media')->fetchColumn();
    
    ob_start();
    ?>
    <div class="section-header"><h2 class="section-title">️ الإعدادات</h2></div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;">
        <div class="form-card">
            <h3 style="margin-bottom:20px;">🎨 المظهر</h3>
            <form method="post" action="?action=save_settings">
                <?= token() ?>
                <div class="form-group"><label class="form-label">عنوان الموقع</label><input type="text" name="site_title" class="form-input" value="<?= h(setting($d,'site_title','LUFLY Catalog')) ?>"></div>
                <div class="form-group"><label class="form-label">اللون الرئيسي</label><div style="display:flex;gap:8px;"><input type="color" name="primary_color" value="<?= h(setting($d,'primary_color','#1F9E9E')) ?>" style="width:60px;height:40px;border:none;cursor:pointer;"><input type="text" name="primary_color_text" class="form-input" value="<?= h(setting($d,'primary_color','#1F9E9E')) ?>" style="flex:1;"></div></div>
                <div class="form-group"><label class="form-label">اللون الثانوي</label><div style="display:flex;gap:8px;"><input type="color" name="secondary_color" value="<?= h(setting($d,'secondary_color','#147070')) ?>" style="width:60px;height:40px;border:none;cursor:pointer;"><input type="text" name="secondary_color_text" class="form-input" value="<?= h(setting($d,'secondary_color','#147070')) ?>" style="flex:1;"></div></div>
                <button type="submit" class="btn btn-primary">💾 حفظ</button>
            </form>
        </div>
        <div>
            <div class="form-card" style="margin-bottom:20px;">
                <h3 style="margin-bottom:16px;">📊 إحصائيات</h3>
                <div class="detail-row"><div class="detail-label">عدد المنتجات</div><div class="detail-value"><?= $totalProducts ?></div></div>
                <div class="detail-row"><div class="detail-label">عدد المرفقات</div><div class="detail-value"><?= $totalMedia ?></div></div>
                <div class="detail-row"><div class="detail-label">محرك البيانات</div><div class="detail-value"><?= h($GLOBALS['config']['driver']??'sqlite') ?></div></div>
            </div>
            <div class="form-card">
                <h3 style="margin-bottom:16px;">🔄 إعادة التثبيت</h3>
                <p style="color:var(--text-muted);margin-bottom:16px;font-size:0.9rem;">حذف كل البيانات وإعادة الاستيراد من catalog.json</p>
                <form method="post" action="?action=reinstall" onsubmit="return confirm('متأكد؟ كل التعديلات هتتمسح!');"><?= token() ?><button type="submit" class="btn btn-danger">⚠️ إعادة التثبيت</button></form>
            </div>
        </div>
    </div>
    <?php
    
    layout('الإعدادات', ob_get_clean());
}

// ─── ERROR PAGES ───────────────────────────────────────────
function showError(string $message): void {
    ob_start();
    ?><div class="error-box"><h3>❌ حدث خطأ</h3><p><?= h($message) ?></p><a href="?action=home" class="btn btn-outline" style="margin-top:16px;">العودة للرئيسية</a></div><?php
    layout('خطأ', ob_get_clean());
}

function showSetupError(string $message): void {
    ob_start();
    ?>
    <div class="error-box">
        <h3>⚠️ تعذر الاتصال بقاعدة البيانات</h3>
        <p style="margin:12px 0;"><?= h($message) ?></p>
        <div style="text-align:right;margin-top:20px;padding:16px;background:rgba(0,0,0,0.05);border-radius:8px;">
            <h4 style="margin-bottom:10px;"> خطوات الحل:</h4>
            <ol style="padding-right:20px;line-height:2;">
                <li>افتح <strong>XAMPP Control Panel</strong></li>
                <li>تأكد إن <strong>MySQL شغال</strong> (لو مش شغال اضغط Start)</li>
                <li>بعد ما MySQL يشتغل، حدّث الصفحة (F5)</li>
            </ol>
        </div>
        <div style="margin-top:20px;">
            <a href="setup.php" class="btn btn-primary" style="margin:4px;">🔍 فحص النظام</a>
            <a href="?action=home" class="btn btn-outline" style="margin:4px;">🔄 تحديث</a>
        </div>
    </div>
    <?php
    layout('خطأ في الإعداد', ob_get_clean());
}
