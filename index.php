<?php
declare(strict_types=1);
require __DIR__.'/app.php';

// ─── Routing ──────────────────────────────────────────────
$action = $_GET['action'] ?? 'home';
$page = $_GET['page'] ?? 1;

try {
    // Auto-install if needed
    $needsInstall = false;
    try {
        $testDb = db();
        $testDb->query('SELECT COUNT(*) FROM products');
    } catch (Throwable $e) {
        $needsInstall = true;
    }
    if ($needsInstall) {
        try { install(); } catch (Throwable $e) {
            // Maybe DB not created yet, try again
            try { install(); } catch (Throwable $e2) {
                $action = 'setup_error';
                $setupError = $e2->getMessage();
            }
        }
    }

    switch ($action) {
        case 'home':
            showHome($page);
            break;
        case 'group':
            showGroup($_GET['prefix'] ?? '', $page);
            break;
        case 'search':
            showSearch($_GET['q'] ?? '', $page);
            break;
        case 'view':
            showProduct((int)($_GET['id'] ?? 0));
            break;
        case 'edit':
            editProduct((int)($_GET['id'] ?? 0));
            break;
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
                if ($id) {
                    run(db(), 'UPDATE products SET archived=1 WHERE id=?', [$id]);
                    flash('تم نقل المنتج للأرشيف');
                }
                go('?action=home');
            }
            break;
        case 'restore':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                checkCsrf();
                $id = (int)($_POST['id'] ?? 0);
                if ($id) {
                    run(db(), 'UPDATE products SET archived=0 WHERE id=?', [$id]);
                    flash('تم استعادة المنتج ✓');
                }
                go('?action=archive_list');
            }
            break;
        case 'archive_list':
            showArchive($page);
            break;
        case 'delete':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                checkCsrf();
                $id = (int)($_POST['id'] ?? 0);
                if ($id) {
                    // Delete media files
                    $media = mediaFor(db(), $id);
                    foreach ($media as $m) {
                        @unlink(__DIR__ . '/' . $m['path']);
                    }
                    run(db(), 'DELETE FROM products WHERE id=?', [$id]);
                    flash('تم حذف المنتج نهائياً');
                }
                go('?action=home');
            }
            break;
        case 'settings':
            showSettings();
            break;
        case 'save_settings':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                checkCsrf();
                $d = db();
                setSetting($d, 'site_title', trim($_POST['site_title'] ?? 'LUFLY Catalog'));
                // Use the text input if available, otherwise the color picker
                $primary = trim($_POST['primary_color_text'] ?? ($_POST['primary_color'] ?? '#1565C0'));
                $secondary = trim($_POST['secondary_color_text'] ?? ($_POST['secondary_color'] ?? '#0D47A1'));
                setSetting($d, 'primary_color', $primary);
                setSetting($d, 'secondary_color', $secondary);
                flash('تم حفظ الإعدادات ✓');
                go('?action=settings');
            }
            break;
        case 'reinstall':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                checkCsrf();
                // Drop and reinstall
                $d = db();
                $d->exec('DROP TABLE IF EXISTS media');
                $d->exec('DROP TABLE IF EXISTS products');
                $d->exec('DROP TABLE IF EXISTS settings');
                setSetting($d, 'installed', '0');
                install();
                flash('تم إعادة التثبيت بنجاح ✓');
                go('?action=home');
            }
            break;
        case 'setup_error':
            showSetupError($setupError ?? 'خطأ غير معروف');
            break;
        default:
            showHome(1);
    }
} catch (Throwable $e) {
    showError($e->getMessage());
}

// ═══════════════════════════════════════════════════════════
// PAGE FUNCTIONS
// ═══════════════════════════════════════════════════════════

function layout(string $title, string $content, string $extraHead = '', string $extraJs = ''): void
{
    $d = db();
    $siteTitle = setting($d, 'site_title', 'LUFLY Catalog');
    $primary = setting($d, 'primary_color', '#1F9E9E');
    $secondary = setting($d, 'secondary_color', '#147070');
    $flash = $_SESSION['flash'] ?? '';
    unset($_SESSION['flash']);
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
    --bg: #f0f7f6;
    --card: #ffffff;
    --text: #1a1a2e;
    --text-muted: #6b7280;
    --border: #e5e7eb;
    --success: #10b981;
    --warning: #f59e0b;
    --danger: #ef4444;
    --radius: 12px;
    --shadow: 0 2px 8px rgba(0,0,0,0.08);
    --shadow-lg: 0 8px 24px rgba(0,0,0,0.12);
}
* { margin: 0; padding: 0; box-sizing: border-box; }
body {
    font-family: 'Segoe UI', Tahoma, Arial, sans-serif;
    background: var(--bg);
    color: var(--text);
    line-height: 1.6;
    min-height: 100vh;
}
/* Header */
.header {
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    color: white;
    padding: 0;
    box-shadow: var(--shadow);
    position: sticky;
    top: 0;
    z-index: 100;
}
.header-inner {
    max-width: 1400px;
    margin: 0 auto;
    padding: 12px 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
}
.logo {
    font-size: 1.5rem;
    font-weight: 800;
    letter-spacing: 2px;
    text-decoration: none;
    color: white;
    display: flex;
    align-items: center;
    gap: 12px;
}
.logo-icon {
    width: 40px;
    height: 40px;
    background: rgba(255,255,255,0.2);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
}
.nav {
    display: flex;
    gap: 8px;
    align-items: center;
}
.nav a {
    color: rgba(255,255,255,0.85);
    text-decoration: none;
    padding: 8px 16px;
    border-radius: 8px;
    font-size: 0.9rem;
    transition: all 0.2s;
}
.nav a:hover, .nav a.active {
    background: rgba(255,255,255,0.15);
    color: white;
}
/* Search Bar */
.search-bar {
    max-width: 1400px;
    margin: 0 auto;
    padding: 0 24px 16px;
}
.search-form {
    display: flex;
    gap: 8px;
}
.search-form input {
    flex: 1;
    padding: 12px 20px;
    border: none;
    border-radius: 10px;
    background: rgba(255,255,255,0.15);
    color: white;
    font-size: 1rem;
    outline: none;
    direction: rtl;
}
.search-form input::placeholder { color: rgba(255,255,255,0.6); }
.search-form input:focus { background: rgba(255,255,255,0.25); }
.search-form button {
    padding: 12px 24px;
    background: rgba(255,255,255,0.2);
    color: white;
    border: none;
    border-radius: 10px;
    cursor: pointer;
    font-size: 1rem;
    transition: background 0.2s;
}
.search-form button:hover { background: rgba(255,255,255,0.3); }
/* Content */
.container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 24px;
}
/* Flash */
.flash {
    background: var(--success);
    color: white;
    padding: 14px 20px;
    border-radius: var(--radius);
    margin-bottom: 20px;
    font-weight: 600;
    animation: slideDown 0.3s ease;
}
@keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
/* Cards */
.card {
    background: var(--card);
    border-radius: var(--radius);
    box-shadow: var(--shadow);
    overflow: hidden;
    transition: transform 0.2s, box-shadow 0.2s;
}
.card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-lg);
}
/* Stats */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}
.stat-card {
    background: var(--card);
    padding: 24px;
    border-radius: var(--radius);
    box-shadow: var(--shadow);
    text-align: center;
    border-top: 4px solid var(--primary);
}
.stat-number {
    font-size: 2.5rem;
    font-weight: 800;
    color: var(--primary);
}
.stat-label {
    color: var(--text-muted);
    font-size: 0.9rem;
    margin-top: 4px;
}
/* Groups */
.groups-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 16px;
}
.group-card {
    background: var(--card);
    border-radius: var(--radius);
    padding: 20px;
    box-shadow: var(--shadow);
    text-decoration: none;
    color: var(--text);
    transition: all 0.2s;
    border-right: 4px solid var(--primary);
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.group-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-lg);
    border-right-color: var(--primary-dark);
}
.group-code {
    font-size: 1.3rem;
    font-weight: 700;
    color: var(--primary);
}
.group-count {
    background: var(--primary-light);
    color: var(--primary);
    padding: 6px 14px;
    border-radius: 20px;
    font-weight: 700;
    font-size: 0.9rem;
}
/* Products Grid */
.products-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 20px;
}
.product-card {
    background: var(--card);
    border-radius: var(--radius);
    overflow: hidden;
    box-shadow: var(--shadow);
    transition: all 0.2s;
}
.product-card:hover {
    transform: translateY(-3px);
    box-shadow: var(--shadow-lg);
}
.product-image {
    width: 100%;
    height: 200px;
    object-fit: cover;
    background: #f0f0f0;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--text-muted);
}
.product-image img {
    width: 100%;
    height: 200px;
    object-fit: cover;
}
.product-info {
    padding: 16px;
}
.product-code {
    font-weight: 700;
    color: var(--primary);
    font-size: 0.95rem;
    margin-bottom: 6px;
}
.product-desc {
    font-size: 0.85rem;
    color: var(--text-muted);
    line-height: 1.5;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    min-height: 2.5em;
}
.product-meta {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 12px;
    padding-top: 12px;
    border-top: 1px solid var(--border);
}
.status-badge {
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
}
.status-Running { background: #dcfce7; color: #166534; }
.status-New-Model { background: #dbeafe; color: #1e40af; }
.status-Slow-Moving { background: #fef3c7; color: #92400e; }
.status-Aging { background: #fee2e2; color: #991b1b; }
.media-count {
    color: var(--text-muted);
    font-size: 0.8rem;
}
/* Product Detail */
.product-detail {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 32px;
}
@media (max-width: 768px) {
    .product-detail { grid-template-columns: 1fr; }
}
.detail-gallery {
    background: var(--card);
    border-radius: var(--radius);
    padding: 20px;
    box-shadow: var(--shadow);
}
.gallery-main {
    width: 100%;
    aspect-ratio: 1;
    object-fit: contain;
    border-radius: 8px;
    background: #f8f9fa;
    margin-bottom: 12px;
}
.gallery-thumbs {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}
.gallery-thumb {
    width: 60px;
    height: 60px;
    object-fit: cover;
    border-radius: 8px;
    cursor: pointer;
    border: 2px solid transparent;
    transition: border-color 0.2s;
}
.gallery-thumb:hover, .gallery-thumb.active {
    border-color: var(--primary);
}
.detail-info {
    background: var(--card);
    border-radius: var(--radius);
    padding: 24px;
    box-shadow: var(--shadow);
}
.detail-row {
    padding: 12px 0;
    border-bottom: 1px solid var(--border);
}
.detail-row:last-child { border-bottom: none; }
.detail-label {
    font-weight: 600;
    color: var(--text-muted);
    font-size: 0.85rem;
    margin-bottom: 4px;
}
.detail-value {
    font-size: 1rem;
    color: var(--text);
}
/* Form */
.form-group {
    margin-bottom: 20px;
}
.form-label {
    display: block;
    font-weight: 600;
    margin-bottom: 6px;
    color: var(--text);
    font-size: 0.9rem;
}
.form-input, .form-textarea, .form-select {
    width: 100%;
    padding: 12px 16px;
    border: 2px solid var(--border);
    border-radius: 10px;
    font-size: 1rem;
    transition: border-color 0.2s;
    direction: rtl;
    background: white;
}
.form-input:focus, .form-textarea:focus, .form-select:focus {
    border-color: var(--primary);
    outline: none;
}
.form-textarea { min-height: 120px; resize: vertical; }
/* Buttons */
.btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    border-radius: 10px;
    font-size: 0.95rem;
    font-weight: 600;
    cursor: pointer;
    border: none;
    transition: all 0.2s;
    text-decoration: none;
}
.btn-primary {
    background: var(--primary);
    color: white;
}
.btn-primary:hover { background: var(--primary-dark); }
.btn-success { background: var(--success); color: white; }
.btn-success:hover { background: #059669; }
.btn-danger { background: var(--danger); color: white; }
.btn-danger:hover { background: #dc2626; }
.btn-outline {
    background: transparent;
    color: var(--primary);
    border: 2px solid var(--primary);
}
.btn-outline:hover { background: var(--primary-light); }
.btn-sm { padding: 6px 12px; font-size: 0.85rem; }
/* Pagination */
.pagination {
    display: flex;
    gap: 8px;
    justify-content: center;
    margin-top: 32px;
    flex-wrap: wrap;
}
.pagination a, .pagination span {
    padding: 8px 16px;
    border-radius: 8px;
    text-decoration: none;
    font-weight: 600;
    font-size: 0.9rem;
    transition: all 0.2s;
}
.pagination a {
    background: var(--card);
    color: var(--text);
    box-shadow: var(--shadow);
}
.pagination a:hover { background: var(--primary); color: white; }
.pagination .current {
    background: var(--primary);
    color: white;
}
/* Section Headers */
.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 12px;
}
.section-title {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--text);
}
/* Duplicate & Incomplete badges */
.badge-duplicate {
    background: #fef3c7;
    color: #92400e;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 0.7rem;
    font-weight: 600;
    margin-right: 6px;
}
.badge-incomplete {
    background: #fee2e2;
    color: #991b1b;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 0.7rem;
    font-weight: 600;
    margin-right: 6px;
}
/* Error / Setup */
.error-box {
    background: #fee2e2;
    border: 2px solid #fca5a5;
    border-radius: var(--radius);
    padding: 24px;
    text-align: center;
    color: #991b1b;
}
/* Filter tabs */
.filter-tabs {
    display: flex;
    gap: 8px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}
.filter-tab {
    padding: 8px 16px;
    border-radius: 20px;
    text-decoration: none;
    font-size: 0.85rem;
    font-weight: 600;
    transition: all 0.2s;
    background: var(--card);
    color: var(--text);
    box-shadow: var(--shadow);
}
.filter-tab:hover, .filter-tab.active {
    background: var(--primary);
    color: white;
}
/* Media management in edit */
.media-list {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: 12px;
    margin-top: 12px;
}
.media-item {
    position: relative;
    border-radius: 8px;
    overflow: hidden;
    background: #f8f9fa;
    border: 2px solid var(--border);
}
.media-item img {
    width: 100%;
    height: 120px;
    object-fit: cover;
}
.media-item-actions {
    padding: 8px;
    display: flex;
    gap: 4px;
    flex-wrap: wrap;
}
.media-item-actions select, .media-item-actions input {
    font-size: 0.75rem;
    padding: 4px;
    border: 1px solid var(--border);
    border-radius: 4px;
}
.remove-check {
    display: flex;
    align-items: center;
    gap: 4px;
    color: var(--danger);
    font-size: 0.75rem;
    cursor: pointer;
}
/* Responsive */
@media (max-width: 640px) {
    .header-inner { flex-direction: column; text-align: center; }
    .nav { justify-content: center; }
    .stats-grid { grid-template-columns: repeat(2, 1fr); }
    .products-grid { grid-template-columns: 1fr; }
    .container { padding: 16px; }
}
/* No image placeholder */
.no-image {
    width: 100%;
    height: 200px;
    background: linear-gradient(135deg, #f0f0f0, #e0e0e0);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #999;
    font-size: 2rem;
}
/* Back link */
.back-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: var(--primary);
    text-decoration: none;
    font-weight: 600;
    margin-bottom: 16px;
}
.back-link:hover { text-decoration: underline; }
</style>
<?= $extraHead ?>
</head>
<body>

<div class="header">
    <div class="header-inner">
        <a href="?action=home" class="logo">
            <img src="<?= h($config['logo_path'] ?? 'assets/logo.png') ?>" alt="LUFLY" style="height:36px; width:auto;">
        </a>
        <nav class="nav">
            <a href="?action=home" class="<?= $action==='home'?'active':'' ?>">🏠 الرئيسية</a>
            <a href="?action=archive_list" class="<?= $action==='archive_list'?'active':'' ?>">📦 الأرشيف</a>
            <a href="?action=settings" class="<?= $action==='settings'?'active':'' ?>">⚙️ الإعدادات</a>
        </nav>
    </div>
    <div class="search-bar">
        <form class="search-form" action="?action=search" method="get">
            <input type="text" name="q" placeholder="ابحث بالكود أو الوصف..." value="<?= h($_GET['q'] ?? '') ?>">
            <button type="submit">🔍 بحث</button>
        </form>
    </div>
</div>

<div class="container">
    <?php if ($flash): ?>
        <div class="flash"><?= h($flash) ?></div>
    <?php endif; ?>
    <?= $content ?>
</div>

<?= $extraJs ?>
</body>
</html>
    <?php
}

function renderProducts(array $products, int $page, int $totalPages, string $baseUrl): void
{
    if (empty($products)) {
        echo '<div style="text-align:center; padding:60px; color:var(--text-muted);">
            <div style="font-size:3rem; margin-bottom:16px;">📭</div>
            <p style="font-size:1.1rem;">لا توجد منتجات</p>
        </div>';
        return;
    }
    echo '<div class="products-grid">';
    foreach ($products as $p) {
        $badges = '';
        if (!empty($p['duplicate'])) $badges .= '<span class="badge-duplicate">⚠ مكرر</span>';
        if (!empty($p['incomplete'])) $badges .= '<span class="badge-incomplete">⚠ ناقص</span>';
        
        $cover = '';
        if (!empty($p['cover'])) {
            $cover = '<img src="' . h($p['cover']) . '" alt="" loading="lazy">';
        } else {
            $cover = '<div class="no-image">📷</div>';
        }
        
        $statusClass = 'status-' . str_replace(' ', '-', $p['status']);
        
        echo '<a href="?action=view&id=' . $p['id'] . '" class="product-card" style="text-decoration:none; color:inherit;">';
        echo '<div class="product-image">' . $cover . '</div>';
        echo '<div class="product-info">';
        echo '<div class="product-code">' . $badges . h($p['code']) . '</div>';
        echo '<div class="product-desc">' . h(mb_substr($p['description'], 0, 100)) . '</div>';
        echo '<div class="product-meta">';
        echo '<span class="status-badge ' . $statusClass . '">' . h($p['status']) . '</span>';
        echo '<span class="media-count">📎 ' . (int)$p['media_count'] . ' صورة</span>';
        echo '</div>';
        echo '</div>';
        echo '</a>';
    }
    echo '</div>';
    
    if ($totalPages > 1) {
        echo '<div class="pagination">';
        if ($page > 1) {
            echo '<a href="' . $baseUrl . '&page=' . ($page - 1) . '">→ السابق</a>';
        }
        $start = max(1, $page - 3);
        $end = min($totalPages, $page + 3);
        for ($i = $start; $i <= $end; $i++) {
            if ($i === $page) {
                echo '<span class="current">' . $i . '</span>';
            } else {
                echo '<a href="' . $baseUrl . '&page=' . $i . '">' . $i . '</a>';
            }
        }
        if ($page < $totalPages) {
            echo '<a href="' . $baseUrl . '&page=' . ($page + 1) . '">التالي ←</a>';
        }
        echo '</div>';
    }
}

// ─── PAGE: Home ────────────────────────────────────────────
function showHome(int $page): void
{
    $d = db();
    $allProducts = allProducts($d, false);
    $totalProducts = count($allProducts);
    $totalMedia = (int)$d->query('SELECT COUNT(*) FROM media')->fetchColumn();
    
    // Group by code prefix
    $groups = [];
    foreach ($allProducts as $p) {
        $key = $p['group'];
        if (!isset($groups[$key])) $groups[$key] = 0;
        $groups[$key]++;
    }
    arsort($groups);
    
    // Status counts
    $statusCounts = [];
    foreach ($allProducts as $p) {
        $s = $p['status'];
        $statusCounts[$s] = ($statusCounts[$s] ?? 0) + 1;
    }
    
    // Recent products (latest 8)
    $recent = array_slice($allProducts, -8);
    $recent = array_reverse($recent);
    
    ob_start();
    ?>
    
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-number"><?= $totalProducts ?></div>
            <div class="stat-label">إجمالي المنتجات</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?= count($groups) ?></div>
            <div class="stat-label">مجموعة كود</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?= $totalMedia ?></div>
            <div class="stat-label">صور ومرفقات</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?= $statusCounts['Running'] ?? 0 ?></div>
            <div class="stat-label">Running</div>
        </div>
    </div>

    <div class="section-header">
        <h2 class="section-title">📂 المجموعات حسب الكود</h2>
    </div>
    <div class="groups-grid">
        <?php foreach ($groups as $prefix => $count): ?>
        <a href="?action=group&prefix=<?= urlencode($prefix) ?>" class="group-card">
            <span class="group-code"><?= h($prefix) ?></span>
            <span class="group-count"><?= $count ?> منتج</span>
        </a>
        <?php endforeach; ?>
    </div>

    <div style="margin-top: 32px;">
        <div class="section-header">
            <h2 class="section-title">🕐 آخر المنتجات المضافة</h2>
        </div>
        <div class="products-grid">
            <?php foreach ($recent as $p): 
                $badges = '';
                if (!empty($p['duplicate'])) $badges .= '<span class="badge-duplicate">⚠ مكرر</span>';
                if (!empty($p['incomplete'])) $badges .= '<span class="badge-incomplete">⚠ ناقص</span>';
                $cover = !empty($p['cover']) ? '<img src="'.h($p['cover']).'" alt="" loading="lazy">' : '<div class="no-image">📷</div>';
                $statusClass = 'status-' . str_replace(' ', '-', $p['status']);
            ?>
            <a href="?action=view&id=<?= $p['id'] ?>" class="product-card" style="text-decoration:none; color:inherit;">
                <div class="product-image"><?= $cover ?></div>
                <div class="product-info">
                    <div class="product-code"><?= $badges ?><?= h($p['code']) ?></div>
                    <div class="product-desc"><?= h(mb_substr($p['description'], 0, 100)) ?></div>
                    <div class="product-meta">
                        <span class="status-badge <?= $statusClass ?>"><?= h($p['status']) ?></span>
                        <span class="media-count">📎 <?= (int)$p['media_count'] ?></span>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
    
    layout('الرئيسية', ob_get_clean());
}

// ─── PAGE: Group View ──────────────────────────────────────
function showGroup(string $prefix, int $page): void
{
    $d = db();
    $allProducts = allProducts($d, false);
    $filtered = array_filter($allProducts, fn($p) => $p['group'] === $prefix);
    $filtered = array_values($filtered);
    
    $perPage = 24;
    $total = count($filtered);
    $totalPages = max(1, (int)ceil($total / $perPage));
    $page = max(1, min($page, $totalPages));
    $offset = ($page - 1) * $perPage;
    $paged = array_slice($filtered, $offset, $perPage);
    
    ob_start();
    ?>
    <a href="?action=home" class="back-link">→ العودة للرئيسية</a>
    <div class="section-header">
        <h2 class="section-title">📦 مجموعة الكود: <?= h($prefix) ?></h2>
        <span style="color:var(--text-muted)"><?= $total ?> منتج في هذه المجموعة</span>
    </div>
    
    <div class="filter-tabs">
        <?php foreach (['الكل' => '', 'Running' => 'Running', 'New Model' => 'New+Model', 'Slow Moving' => 'Slow+Moving', 'Aging' => 'Aging'] as $label => $val): ?>
        <a href="?action=group&prefix=<?= urlencode($prefix) ?>&status=<?= urlencode($val) ?>" 
           class="filter-tab"><?= $label ?></a>
        <?php endforeach; ?>
    </div>
    <?php
    
    // Apply status filter
    $statusFilter = $_GET['status'] ?? '';
    if ($statusFilter) {
        $paged = array_filter($paged, fn($p) => $p['status'] === $statusFilter);
        $paged = array_values($paged);
    }
    
    renderProducts($paged, $page, $totalPages, '?action=group&prefix=' . urlencode($prefix) . '&status=' . urlencode($statusFilter));
    
    layout('مجموعة ' . $prefix, ob_get_clean());
}

// ─── PAGE: Search ──────────────────────────────────────────
function showSearch(string $q, int $page): void
{
    $d = db();
    $q = trim($q);
    $allProducts = allProducts($d, false);
    
    if ($q === '') {
        $filtered = $allProducts;
    } else {
        $filtered = array_filter($allProducts, function($p) use ($q) {
            return stripos($p['code'], $q) !== false 
                || stripos($p['description'], $q) !== false
                || stripos($p['brand'], $q) !== false;
        });
        $filtered = array_values($filtered);
    }
    
    $perPage = 24;
    $total = count($filtered);
    $totalPages = max(1, (int)ceil($total / $perPage));
    $page = max(1, min($page, $totalPages));
    $offset = ($page - 1) * $perPage;
    $paged = array_slice($filtered, $offset, $perPage);
    
    ob_start();
    ?>
    <a href="?action=home" class="back-link">→ العودة للرئيسية</a>
    <div class="section-header">
        <h2 class="section-title">🔍 نتائج البحث: "<?= h($q) ?>"</h2>
        <span style="color:var(--text-muted)"><?= $total ?> نتيجة</span>
    </div>
    <?php
    
    renderProducts($paged, $page, $totalPages, '?action=search&q=' . urlencode($q));
    
    layout('بحث: ' . $q, ob_get_clean());
}

// ─── PAGE: View Product ───────────────────────────────────
function showProduct(int $id): void
{
    $d = db();
    $product = run($d, 'SELECT * FROM products WHERE id=?', [$id])->fetch();
    if (!$product) { showError('المنتج غير موجود'); return; }
    
    $media = mediaFor($d, $id);
    $group = groupCode($product['code']);
    
    ob_start();
    ?>
    <a href="?action=group&prefix=<?= urlencode($group) ?>" class="back-link">→ العودة لمجموعة <?= h($group) ?></a>
    
    <div class="section-header">
        <h2 class="section-title">
            <?= h($product['code']) ?>
            <span class="status-badge status-<?= str_replace(' ', '-', $product['status']) ?>">
                <?= h($product['status']) ?>
            </span>
        </h2>
        <div style="display:flex; gap:8px;">
            <a href="?action=edit&id=<?= $id ?>" class="btn btn-primary">✏️ تعديل</a>
            <form method="post" action="?action=archive" style="display:inline;">
                <?= token() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <button type="submit" class="btn btn-outline btn-sm">📦 أرشفة</button>
            </form>
        </div>
    </div>
    
    <div class="product-detail">
        <div class="detail-gallery">
            <?php if (!empty($media)): ?>
                <?php 
                $previewable = array_filter($media, fn($m) => (int)$m['previewable']);
                $first = reset($previewable);
                if ($first): ?>
                    <img src="<?= h($first['path']) ?>" class="gallery-main" id="mainImage" alt="">
                <?php endif; ?>
                <?php if (count($previewable) > 1): ?>
                <div class="gallery-thumbs">
                    <?php foreach ($previewable as $m): ?>
                    <img src="<?= h($m['path']) ?>" class="gallery-thumb" 
                         onclick="document.getElementById('mainImage').src='<?= h($m['path']) ?>'"
                         alt="">
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            <?php else: ?>
                <div style="text-align:center; padding:60px; color:var(--text-muted);">
                    <div style="font-size:4rem;">📷</div>
                    <p>لا توجد صور</p>
                    <a href="?action=edit&id=<?= $id ?>" class="btn btn-outline" style="margin-top:12px;">إضافة صور</a>
                </div>
            <?php endif; ?>
            
            <?php 
            $nonPreviewable = array_filter($media, fn($m) => !(int)$m['previewable']);
            if (!empty($nonPreviewable)): ?>
            <div style="margin-top:16px; padding-top:16px; border-top:1px solid var(--border);">
                <strong>مرفقات أخرى:</strong>
                <?php foreach ($nonPreviewable as $m): ?>
                <div style="margin-top:8px;">
                    <a href="<?= h($m['path']) ?>" download class="btn btn-sm btn-outline">
                        📥 <?= h($m['original_name'] ?: 'تحميل') ?>
                    </a>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        
        <div class="detail-info">
            <div class="detail-row">
                <div class="detail-label">الكود</div>
                <div class="detail-value" style="font-size:1.3rem; font-weight:700; color:var(--primary);">
                    <?= h($product['code']) ?>
                </div>
            </div>
            <div class="detail-row">
                <div class="detail-label">الماركة</div>
                <div class="detail-value"><?= h($product['brand']) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">الحالة</div>
                <div class="detail-value">
                    <span class="status-badge status-<?= str_replace(' ', '-', $product['status']) ?>">
                        <?= h($product['status']) ?>
                    </span>
                </div>
            </div>
            <div class="detail-row">
                <div class="detail-label">الوصف</div>
                <div class="detail-value" style="white-space:pre-wrap;"><?= h($product['description'] ?: '—') ?></div>
            </div>
            <?php if ($product['notes']): ?>
            <div class="detail-row">
                <div class="detail-label">ملاحظات</div>
                <div class="detail-value" style="white-space:pre-wrap;"><?= h($product['notes']) ?></div>
            </div>
            <?php endif; ?>
            <div class="detail-row">
                <div class="detail-label">مجموعة الكود</div>
                <div class="detail-value">
                    <a href="?action=group&prefix=<?= urlencode($group) ?>"><?= h($group) ?></a>
                </div>
            </div>
            <div class="detail-row">
                <div class="detail-label">عدد المرفقات</div>
                <div class="detail-value"><?= count($media) ?> مرفق</div>
            </div>
            <div class="detail-row">
                <div class="detail-label">الملف المصدر</div>
                <div class="detail-value"><?= h($product['source_file'] ?: '—') ?> (سطر <?= (int)$product['source_row'] ?>)</div>
            </div>
        </div>
    </div>
    <?php
    
    layout($product['code'], ob_get_clean());
}

// ─── PAGE: Edit/Add Product ───────────────────────────────
function editProduct(int $id): void
{
    $d = db();
    $product = $id ? run($d, 'SELECT * FROM products WHERE id=?', [$id])->fetch() : null;
    if ($id && !$product) { showError('المنتج غير موجود'); return; }
    
    $media = $id ? mediaFor($d, $id) : [];
    $title = $id ? 'تعديل: ' . $product['code'] : 'إضافة منتج جديد';
    
    ob_start();
    ?>
    <a href="<?= $id ? '?action=view&id='.$id : '?action=home' ?>" class="back-link">→ العودة</a>
    
    <div class="section-header">
        <h2 class="section-title"><?= $id ? '✏️ تعديل المنتج' : '➕ إضافة منتج جديد' ?></h2>
    </div>
    
    <form method="post" action="?action=save" enctype="multipart/form-data" style="background:var(--card); padding:24px; border-radius:var(--radius); box-shadow:var(--shadow);">
        <?= token() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="version" value="<?= $product['version'] ?? 1 ?>">
        
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
            <div class="form-group">
                <label class="form-label">الكود (Code) *</label>
                <input type="text" name="code" class="form-input" value="<?= h($product['code'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">الماركة (Brand)</label>
                <input type="text" name="brand" class="form-input" value="<?= h($product['brand'] ?? 'lufly') ?>">
            </div>
        </div>
        
        <div class="form-group">
            <label class="form-label">الحالة (Status)</label>
            <select name="status" class="form-select">
                <?php foreach (STATUSES as $s): ?>
                <option value="<?= h($s) ?>" <?= ($product['status'] ?? '') === $s ? 'selected' : '' ?>><?= h($s) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="form-group">
            <label class="form-label">الوصف (Description)</label>
            <textarea name="description" class="form-textarea"><?= h($product['description'] ?? '') ?></textarea>
        </div>
        
        <div class="form-group">
            <label class="form-label">ملاحظات (Notes)</label>
            <textarea name="notes" class="form-textarea" style="min-height:80px;"><?= h($product['notes'] ?? '') ?></textarea>
        </div>
        
        <?php if (!empty($media)): ?>
        <div class="form-group">
            <label class="form-label">المرفقات الحالية</label>
            <div class="media-list">
                <?php foreach ($media as $m): ?>
                <div class="media-item">
                    <?php if ((int)$m['previewable']): ?>
                        <img src="<?= h($m['path']) ?>" alt="">
                    <?php else: ?>
                        <div style="height:120px; display:flex; align-items:center; justify-content:center; background:#f0f0f0;">📎</div>
                    <?php endif; ?>
                    <div class="media-item-actions">
                        <select name="kind[<?= $m['id'] ?>]">
                            <option value="photo" <?= $m['kind']==='photo'?'selected':'' ?>>صورة</option>
                            <option value="drawing" <?= $m['kind']==='drawing'?'selected':'' ?>>رسم</option>
                            <option value="extra" <?= $m['kind']==='extra'?'selected':'' ?>>إضافي</option>
                        </select>
                        <input type="text" name="caption[<?= $m['id'] ?>]" value="<?= h($m['caption']) ?>" placeholder="وصف..." style="width:100%;">
                        <input type="number" name="sort[<?= $m['id'] ?>]" value="<?= (int)$m['sort_order'] ?>" style="width:60px;" title="ترتيب">
                        <label class="remove-check">
                            <input type="checkbox" name="remove_media[<?= $m['id'] ?>]" value="1"> حذف
                        </label>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="form-group">
            <label class="form-label">نوع المرفق الجديد</label>
            <select name="upload_kind" class="form-select" style="max-width:300px;">
                <option value="photo">صورة المنتج</option>
                <option value="drawing">رسم فني</option>
                <option value="extra">صورة إضافية</option>
            </select>
        </div>
        
        <div class="form-group">
            <label class="form-label">رفع صور جديدة</label>
            <input type="file" name="files[]" multiple accept="image/*" class="form-input" style="padding:10px;">
            <small style="color:var(--text-muted);">JPG, PNG, WebP, GIF — حد أقصى 12MB لكل صورة</small>
        </div>
        
        <div style="display:flex; gap:12px; margin-top:24px;">
            <button type="submit" class="btn btn-success" style="font-size:1.1rem; padding:14px 32px;">
                💾 حفظ المنتج
            </button>
            <a href="?action=<?= $id ? 'view&id='.$id : 'home' ?>" class="btn btn-outline">إلغاء</a>
        </div>
    </form>
    <?php
    
    layout($title, ob_get_clean());
}

// ─── PAGE: Archive List ───────────────────────────────────
function showArchive(int $page): void
{
    $d = db();
    $allProducts = allProducts($d, true);
    
    $perPage = 24;
    $total = count($allProducts);
    $totalPages = max(1, (int)ceil($total / $perPage));
    $page = max(1, min($page, $totalPages));
    $offset = ($page - 1) * $perPage;
    $paged = array_slice($allProducts, $offset, $perPage);
    
    ob_start();
    ?>
    <a href="?action=home" class="back-link">→ العودة للرئيسية</a>
    <div class="section-header">
        <h2 class="section-title">📦 الأرشيف</h2>
        <span style="color:var(--text-muted)"><?= $total ?> منتج مؤرشف</span>
    </div>
    <?php
    
    if (empty($paged)) {
        echo '<div style="text-align:center; padding:60px; color:var(--text-muted);">
            <div style="font-size:3rem;">📭</div>
            <p>لا توجد منتجات في الأرشيف</p>
        </div>';
    } else {
        echo '<div class="products-grid">';
        foreach ($paged as $p) {
            $cover = !empty($p['cover']) ? '<img src="'.h($p['cover']).'" alt="" loading="lazy">' : '<div class="no-image">📷</div>';
            echo '<div class="product-card">';
            echo '<div class="product-image">' . $cover . '</div>';
            echo '<div class="product-info">';
            echo '<div class="product-code">' . h($p['code']) . '</div>';
            echo '<div class="product-desc">' . h(mb_substr($p['description'], 0, 80)) . '</div>';
            echo '<form method="post" action="?action=restore" style="margin-top:12px;">';
            echo token();
            echo '<input type="hidden" name="id" value="' . $p['id'] . '">';
            echo '<button type="submit" class="btn btn-primary btn-sm">↩️ استعادة</button>';
            echo '</form>';
            echo '</div></div>';
        }
        echo '</div>';
        
        if ($totalPages > 1) {
            echo '<div class="pagination">';
            for ($i = 1; $i <= $totalPages; $i++) {
                if ($i === $page) {
                    echo '<span class="current">' . $i . '</span>';
                } else {
                    echo '<a href="?action=archive_list&page=' . $i . '">' . $i . '</a>';
                }
            }
            echo '</div>';
        }
    }
    
    layout('الأرشيف', ob_get_clean());
}

// ─── PAGE: Settings ───────────────────────────────────────
function showSettings(): void
{
    $d = db();
    $totalProducts = (int)$d->query('SELECT COUNT(*) FROM products')->fetchColumn();
    $totalMedia = (int)$d->query('SELECT COUNT(*) FROM media')->fetchColumn();
    
    ob_start();
    ?>
    <a href="?action=home" class="back-link">→ العودة للرئيسية</a>
    <div class="section-header">
        <h2 class="section-title">⚙️ الإعدادات</h2>
    </div>
    
    <div style="display:grid; grid-template-columns:1fr 1fr; gap:24px;">
        <div style="background:var(--card); padding:24px; border-radius:var(--radius); box-shadow:var(--shadow);">
            <h3 style="margin-bottom:20px;">🎨 المظهر</h3>
            <form method="post" action="?action=save_settings">
                <?= token() ?>
                <div class="form-group">
                    <label class="form-label">عنوان الموقع</label>
                    <input type="text" name="site_title" class="form-input" value="<?= h(setting($d, 'site_title', 'LUFLY Catalog')) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">اللون الرئيسي</label>
                    <div style="display:flex; gap:8px; align-items:center;">
                        <input type="color" name="primary_color" value="<?= h(setting($d, 'primary_color', '#1F9E9E')) ?>" style="width:60px; height:40px; border:none; cursor:pointer;">
                        <input type="text" name="primary_color_text" class="form-input" value="<?= h(setting($d, 'primary_color', '#1F9E9E')) ?>" style="flex:1;">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">اللون الثانوي</label>
                    <div style="display:flex; gap:8px; align-items:center;">
                        <input type="color" name="secondary_color" value="<?= h(setting($d, 'secondary_color', '#147070')) ?>" style="width:60px; height:40px; border:none; cursor:pointer;">
                        <input type="text" name="secondary_color_text" class="form-input" value="<?= h(setting($d, 'secondary_color', '#147070')) ?>" style="flex:1;">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">💾 حفظ الإعدادات</button>
            </form>
        </div>
        
        <div>
            <div style="background:var(--card); padding:24px; border-radius:var(--radius); box-shadow:var(--shadow); margin-bottom:20px;">
                <h3 style="margin-bottom:16px;">📊 إحصائيات</h3>
                <div class="detail-row">
                    <div class="detail-label">عدد المنتجات</div>
                    <div class="detail-value"><?= $totalProducts ?></div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">عدد المرفقات</div>
                    <div class="detail-value"><?= $totalMedia ?></div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">محرك البيانات</div>
                    <div class="detail-value"><?= h($GLOBALS['config']['driver'] ?? 'mysql') ?></div>
                </div>
            </div>
            
            <div style="background:var(--card); padding:24px; border-radius:var(--radius); box-shadow:var(--shadow);">
                <h3 style="margin-bottom:16px;">🔄 إعادة التثبيت</h3>
                <p style="color:var(--text-muted); margin-bottom:16px; font-size:0.9rem;">
                    سيؤدي هذا إلى حذف جميع البيانات وإعادة استيرادها من ملف catalog.json الأصلي.
                </p>
                <form method="post" action="?action=reinstall" onsubmit="return confirm('هل أنت متأكد؟ سيتم حذف جميع التعديلات!');">
                    <?= token() ?>
                    <button type="submit" class="btn btn-danger">⚠️ إعادة التثبيت</button>
                </form>
            </div>
        </div>
    </div>
    <?php
    
    layout('الإعدادات', ob_get_clean());
}

// ─── PAGE: Error ──────────────────────────────────────────
function showError(string $message): void
{
    ob_start();
    ?>
    <div class="error-box">
        <div style="font-size:3rem; margin-bottom:16px;">❌</div>
        <h3 style="margin-bottom:8px;">حدث خطأ</h3>
        <p><?= h($message) ?></p>
        <a href="?action=home" class="btn btn-outline" style="margin-top:16px;">العودة للرئيسية</a>
    </div>
    <?php
    layout('خطأ', ob_get_clean());
}

function showSetupError(string $message): void
{
    ob_start();
    ?>
    <div class="error-box">
        <div style="font-size:3rem; margin-bottom:16px;">⚠️</div>
        <h3 style="margin-bottom:8px;">خطأ في الاتصال بقاعدة البيانات</h3>
        <p><?= h($message) ?></p>
        <div style="text-align:right; margin-top:20px; padding:16px; background:rgba(0,0,0,0.05); border-radius:8px;">
            <strong>تأكد من:</strong>
            <ul style="margin-top:8px; list-style:disc; padding-right:20px;">
                <li>تشغيل MySQL من XAMPP</li>
                <li>صحة بيانات الاتصال في config.php</li>
                <li>وجود database بالاسم المطلوب</li>
            </ul>
        </div>
    </div>
    <?php
    layout('خطأ في الإعداد', ob_get_clean());
}
