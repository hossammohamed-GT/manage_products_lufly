<?php
declare(strict_types=1);
require __DIR__.'/app.php';
require_once __DIR__.'/includes/sidebar.php';
require_once __DIR__.'/includes/image_enhancement.php';

// Authentication check
$appPassword = $config['app_password'] ?? '';

if ($appPassword !== '') {
    if (empty($_SESSION['lufly_auth']) || $_SESSION['lufly_auth'] !== true) {
        header('Location: login.php');
        exit;
    }
    
    // Session timeout after 8 hours
    if (isset($_SESSION['lufly_login_time']) && (time() - $_SESSION['lufly_login_time'] > 28800)) {
        foreach (array_keys($_SESSION['enhancements'] ?? []) as $token) discardEnhancement($token);
        session_destroy();
        header('Location: login.php');
        exit;
    }
}

$action = $_GET['action'] ?? 'home';
$page = max(1, (int)($_GET['page'] ?? 1));

try {
    $needsInstall = false;
    try {
        $testDb = db();
        catalogRows($testDb, 'products', ['id'=>0]);
    } catch (Throwable $e) { $needsInstall = true; }
    if ($needsInstall) {
        try { install(); } catch (Throwable $e) {
            $action = 'setup_error';
            $setupError = $e->getMessage();
        }
    }

    if ($action === 'enhancement_preview') {
        try {
            $preview = enhancementPreview(is_string($_GET['token'] ?? null) ? $_GET['token'] : '');
            $original = ($_GET['original'] ?? '') === '1';
            header('Content-Type: ' . ($original ? $preview['mime'] : 'image/png'));
            header('Cache-Control: private, no-store');
            if (!$original && ($_GET['download'] ?? '') === '1')
                header('Content-Disposition: attachment; filename="lufly-high-resolution.png"');
            readfile($original ? $preview['original_file'] : $preview['enhanced_file']);
        } catch (Throwable $e) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo $e->getMessage();
        }
        exit;
    }
    if (in_array($action, ['enhancement_create', 'enhancement_approve', 'enhancement_cancel'], true)) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        try {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new RuntimeException('العملية تتطلب طلب حفظ');
            checkCsrf();
            $token = is_string($_POST['token'] ?? null) ? $_POST['token'] : '';
            if ($action === 'enhancement_create') {
                $result = createEnhancement(db(), (int)($_POST['product_id'] ?? 0), (int)($_POST['media_id'] ?? 0),
                    is_string($_POST['profile'] ?? null) ? $_POST['profile'] : 'whiteware');
            } elseif ($action === 'enhancement_approve') {
                $result = approveEnhancement(db(), $token);
            } else {
                discardEnhancement($token);
                $result = ['ok'=>true];
            }
            echo json_encode($result);
        } catch (Throwable $e) {
            if (isset($d) && $d->inTransaction()) $d->rollBack();
            http_response_code(400);
            echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
        }
        exit;
    }

    // API endpoints
    if ($action === 'toggle_verify' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            checkCsrf();
            $d = db();
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) throw new RuntimeException('معرف المنتج مطلوب');
            $d->beginTransaction();
            $product = requireProduct($d, $id);
            $current = (int)$product['verified'];
            $newVal = $current ? 0 : 1;
            catalogUpdate($d, 'products', ['verified'=>$newVal,'updated_at'=>date('c')], ['id'=>$id,'archived'=>0], true);
            $d->commit();
            echo json_encode(['ok'=>true, 'verified'=>$newVal]);
        } catch (Throwable $e) {
            if (isset($d) && $d->inTransaction()) $d->rollBack();
            http_response_code(400);
            echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
        }
        exit;
    }
    if ($action === 'bulk_verify' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            checkCsrf();
            $d = db();
            $ids = json_decode((string)($_POST['ids'] ?? '[]'), true);
            if (!is_array($ids) || empty($ids) || count($ids) > 1000) throw new RuntimeException('حدد من 1 إلى 1000 منتج');
            foreach ($ids as $id) {
                if (!is_int($id) || $id <= 0) throw new RuntimeException('معرف المنتج غير صالح');
            }
            $ids = array_values(array_unique($ids));
            $d->beginTransaction();
            foreach ($ids as $id) requireProduct($d, $id);
            $updated = catalogUpdate($d, 'products', ['verified'=>1,'updated_at'=>date('c')], ['archived'=>0,'id'=>$ids], true);
            $d->commit();
            echo json_encode(['ok'=>true, 'count'=>$updated]);
        } catch (Throwable $e) {
            if (isset($d) && $d->inTransaction()) $d->rollBack();
            http_response_code(400);
            echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
        }
        exit;
    }

    // Logout
    if ($action === 'logout') {
        foreach (array_keys($_SESSION['enhancements'] ?? []) as $token) discardEnhancement($token);
        session_destroy();
        header('Location: login.php');
        exit;
    }

    if ($action === 'api_products' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $d = db();
            $prefix = $_GET['prefix'] ?? '';
            $allProducts = allProducts($d, false);
            if ($prefix !== '') {
                $filtered = array_values(array_filter($allProducts, fn($p) => $p['group'] === $prefix));
            } else {
                $filtered = $allProducts;
            }
            // Build HTML
            ob_start();
            foreach ($filtered as $p) {
                echo productCardHTML($p);
            }
            $html = ob_get_clean();
            echo json_encode(['ok'=>true, 'html'=>$html, 'count'=>count($filtered)]);
        } catch (Throwable $e) {
            if (isset($d) && $d->inTransaction()) $d->rollBack();
            http_response_code(400);
            echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
        }
        exit;
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
                if (strlen($value) > (in_array($field, ['code', 'brand'], true) ? 190 : 60000)) throw new RuntimeException('النص طويل جداً');
                $now = date('c');
                $changed = catalogUpdate($d, 'products', [$field=>$value,'updated_at'=>$now], ['id'=>$id,'archived'=>0], true);
                if ($changed !== 1) throw new RuntimeException('المنتج غير موجود');
                echo json_encode(['ok'=>true, 'field'=>$field, 'value'=>$value]);
            }
            else if ($action === 'media_action') {
                echo json_encode(changeMedia($d, (string)($_POST['op'] ?? ''),
                    (int)($_POST['product_id'] ?? 0), (int)($_POST['media_id'] ?? 0),
                    (string)($_POST['kind'] ?? 'photo')));
            }
        } catch (Throwable $e) {
            if (isset($d) && $d->inTransaction()) $d->rollBack();
            http_response_code(400);
            echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
        }
        exit;
    }

    if (in_array($action, ['archive', 'restore', 'delete'], true) && $_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            checkCsrf();
            $d = db();
            $id = (int)($_POST['id'] ?? 0);
            requireProduct($d, $id, false);
            if ($action === 'delete') {
                deleteProductRecord($d, $id);
            } else {
                if (catalogUpdate($d, 'products', ['archived'=>$action === 'archive' ? 1 : 0,'updated_at'=>date('c')], ['id'=>$id], true) !== 1)
                    throw new RuntimeException('المنتج غير موجود');
            }
            echo json_encode(['ok'=>true]);
        } catch (Throwable $e) {
            if (isset($d) && $d->inTransaction()) $d->rollBack();
            http_response_code(400);
            echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
        }
        exit;
    }

    switch ($action) {
        case 'home': showHome($page); break;
        case 'group': showGroup($_GET['prefix'] ?? '', $page); break;
        case 'search': showSearch($_GET['q'] ?? '', $page); break;
        case 'archive_list': showArchive($page); break;
        case 'setup_error': showSetupError($setupError ?? 'تعذر إعداد قاعدة البيانات'); break;
        case 'settings': showSettings(); break;
        case 'save_settings':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); showError('استخدم نموذج الإعدادات للحفظ'); break; }
            checkCsrf();
            $title = trim((string)($_POST['site_title'] ?? ''));
            $primary = (string)($_POST['primary_color'] ?? '');
            $secondary = (string)($_POST['secondary_color'] ?? '');
            if ($title === '' || strlen($title) > 190 || !preg_match('/^#[a-fA-F0-9]{6}$/', $primary) || !preg_match('/^#[a-fA-F0-9]{6}$/', $secondary))
                throw new RuntimeException('اكتب عنوانًا صحيحًا واختر ألوانًا صحيحة');
            $d = db();
            $d->beginTransaction();
            try {
                foreach (['site_title'=>$title, 'primary_color'=>$primary, 'secondary_color'=>$secondary] as $key=>$value) setSetting($d, $key, $value);
                $d->commit();
            } catch (Throwable $e) {
                if ($d->inTransaction()) $d->rollBack();
                throw $e;
            }
            flash('تم حفظ الإعدادات');
            go('?action=settings');
            break;
        case 'reinstall':
            http_response_code(400);
            showError('إعادة التثبيت معطّلة لحماية بياناتك من الحذف.');
            break;
        default: showHome(1);
    }
} catch (Throwable $e) {
    showError($e->getMessage());
}

// ═══════════════════════════════════════════════════════════
function layout(string $title, string $content, array $groups = [], string $extraJs = '', string $action = 'home'): void
{
    global $config;
    // Error/setup pages must render even when the database is unavailable.
    $d = null;
    try { $d = db(); } catch (Throwable $e) { }
    $siteTitle = $d ? setting($d, 'site_title', 'LUFLY Catalog') : 'LUFLY Catalog';
    $primary = $d ? setting($d, 'primary_color', '#1F9E9E') : '#1F9E9E';
    $secondary = $d ? setting($d, 'secondary_color', '#147070') : '#147070';
    $flash = $_SESSION['flash'] ?? '';
    unset($_SESSION['flash']);
    $sidebarHtml = $d ? renderSidebar($groups, (string)($_GET['prefix'] ?? '')) : '';
    ?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf" content="<?= h(csrf()) ?>">
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
.top-header .logout-btn { color: var(--text-muted); text-decoration: none; font-size: 1.2rem; transition: color 0.2s; }
.top-header .logout-btn:hover { color: var(--danger); }
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
.products-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(360px, 1fr)); gap: 24px; }
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

/* Verified state */
.product-card.verified {
    border: 2px solid #10b981;
    box-shadow: 0 0 12px rgba(16, 185, 129, 0.2);
}
.product-card.verified .card-carousel {
    border-bottom: 2px solid #10b981;
}

/* Verify check icon */
.verify-check {
    position: absolute;
    top: 12px;
    left: 12px;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: rgba(255,255,255,0.9);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s;
    z-index: 5;
    font-size: 1.2rem;
}
.verify-check:hover {
    background: white;
    transform: scale(1.1);
}
.verify-check .fa-check-circle { color: #10b981; }
.verify-check .fa-circle { color: #cbd5e1; }
.product-card.verified .verify-check {
    background: #10b981;
}
.product-card.verified .verify-check .fa-circle {
    color: white;
}
.product-card.verified .verify-check .fa-circle::before {
    content: '\f058';
    font-family: 'Font Awesome 6 Free';
    font-weight: 900;
}

/* Bulk verify bar */
.bulk-verify-bar {
    display: none;
    position: sticky;
    top: 70px;
    z-index: 50;
    background: #10b981;
    color: white;
    padding: 12px 20px;
    border-radius: 12px;
    margin-bottom: 16px;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
    align-items: center;
    justify-content: space-between;
    animation: slideDown 0.3s ease;
}
.bulk-verify-bar.active { display: flex; }
@keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
.bulk-verify-bar .count { font-weight: 700; font-size: 1rem; }
.bulk-verify-bar .actions { display: flex; gap: 8px; }
.bulk-verify-bar button {
    padding: 8px 16px;
    border: none;
    border-radius: 8px;
    font-weight: 700;
    cursor: pointer;
    font-size: 0.9rem;
    transition: all 0.2s;
}
.btn-confirm-all { background: white; color: #10b981; }
.btn-confirm-all:hover { background: #f0fdf4; }
.btn-clear-selection { background: rgba(255,255,255,0.2); color: white; }
.btn-clear-selection:hover { background: rgba(255,255,255,0.3); }

/* Card Image Carousel */
.card-carousel {
    position: relative;
    height: 260px;
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
    object-fit: contain;
    cursor: pointer;
    background: #f0f2f5;
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
    opacity: 0.7;
}
.carousel-btn:hover { opacity: 1; background: white; box-shadow: 0 2px 8px rgba(0,0,0,0.2); }
.carousel-prev { right: 12px; }
.carousel-next { left: 12px; }
.carousel-dots {
    position: absolute;
    bottom: 12px;
    left: 50%;
    transform: translateX(-50%);
    display: flex;
    gap: 6px;
    z-index: 3;
}
.carousel-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: rgba(0,0,0,0.3);
    transition: all 0.2s;
}
.carousel-dot.active { background: var(--primary); width: 20px; border-radius: 4px; }
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

/* Code Badge */
.code-badge {
    position: absolute;
    bottom: 12px;
    left: 12px;
    background: rgba(31, 158, 158, 0.9);
    color: white;
    padding: 4px 10px;
    border-radius: 8px;
    font-size: 0.72rem;
    font-weight: 600;
    font-family: 'Consolas', monospace;
    z-index: 2;
    backdrop-filter: blur(4px);
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
.lb-enhance { background: #e2f7ef; color: #116348; }
.lightbox-action-btn[hidden] { display: none; }
.lightbox-action-btn:disabled { opacity: .55; cursor: wait; }
.lightbox-actions { max-width: 95vw; flex-wrap: wrap; justify-content: center; }
.enhancement-panel { width: min(1080px, 90vw); max-height: calc(100dvh - 110px); overflow-y: auto; color: white; padding: 24px; background: #18212b; border: 1px solid #43505e; border-radius: 16px; }
.enhancement-panel[hidden] { display: none; }
.enhancement-panel h2 { margin-bottom: 8px; }
.enhancement-panel p { color: #d5dde6; margin: 12px 0; }
.enhancement-compare { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.enhancement-compare figure { min-width: 0; }
.enhancement-compare figcaption { padding: 8px; font-weight: bold; text-align: center; }
.enhancement-compare img { width: 100%; height: min(48vh, 460px); object-fit: contain; background: #e6e9ec; border-radius: 10px; }
.enhancement-profile { color: white; font-size: .8rem; display: grid; gap: 4px; }
.enhancement-profile select { max-width: 260px; padding: 7px; border-radius: 7px; background: #fff; color: #18212b; }
.enhancement-inspect { margin: 12px 0; font-size: .9rem; }
.enhancement-viewport { overflow: auto; border-radius: 10px; background: #e6e9ec; }
.enhancement-compare.actual-size .enhancement-viewport { height: min(48vh, 460px); }
.enhancement-compare.actual-size img { width: auto; height: auto; max-width: none; display: block; }
.enhancement-decisions { display: flex; gap: 12px; flex-wrap: wrap; }
@media (max-width: 650px) {
    .enhancement-compare { grid-template-columns: 1fr; }
    .enhancement-compare img { height: 28vh; }
    .enhancement-panel { padding: 14px; }
    .lightbox-actions { bottom: 12px; gap: 6px; padding: 10px; width: 95vw; }
    .lightbox-action-btn { padding: 10px; }
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
    <nav class="top-nav"><a href="?action=home">المنتجات</a><a href="?action=archive_list">الأرشيف</a><a href="?action=settings">الإعدادات</a></nav>
    <a href="?action=logout" class="logout-btn" title="تسجيل الخروج"><i class="fas fa-sign-out-alt"></i></a>
</div>

<!-- ══ HERO BANNER ═══ -->
<?php if ($d && in_array($action, ['home','group','search'])): ?>
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
                    <div class="hero-stat-num"><?= count(catalogRows($d, 'media')) ?></div>
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
        <?php if ($config['driver'] === 'json'): ?><div class="flash">وضع الملفات المحلي — التعديلات محفوظة في JSON مستقل، وليس في قاعدة البيانات.</div><?php endif; ?>
        <?php if ($flash): ?><div class="flash"><?= h($flash) ?></div><?php endif; ?>
        
        <!-- Bulk verify bar -->
        <div class="bulk-verify-bar" id="bulkVerifyBar">
            <span class="count"><i class="fas fa-check-double"></i> تم تحديد <span id="selectedCount">0</span> منتج</span>
            <div class="actions">
                <button class="btn-confirm-all" onclick="bulkVerifySelected()"><i class="fas fa-check"></i> تأكيد الكل</button>
                <button class="btn-clear-selection" onclick="clearSelection()"><i class="fas fa-times"></i> إلغاء</button>
            </div>
        </div>
        
        <form method="get" class="toolbar" role="search">
            <input type="hidden" name="action" value="search">
            <input type="search" class="toolbar-search" name="q" placeholder="ابحث بالكود أو الوصف أو الماركة" value="<?= h($_GET['q'] ?? '') ?>">
            <button type="submit" class="card-btn">بحث</button>
        </form>
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
    <section id="lbEnhancement" class="enhancement-panel" hidden aria-label="مقارنة تحسين الصورة" aria-live="polite"></section>
    <div class="lightbox-actions" id="lbActions"></div>
</div>

<!-- ═══ TOAST ═══ -->
<div id="toast"></div>

<?php sidebarAssets(); ?>
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
    
    form.onclick = event => event.stopPropagation();
    form.classList.add('active');
    const input = form.querySelector('input, textarea, select');
    if (input) input.focus();
    
    const saveBtn = form.querySelector('.btn-save');
    const cancelBtn = form.querySelector('.btn-cancel');
    
    saveBtn.onclick = function() {
        const value = input.value.trim();
        
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
                    valueEl.textContent = data.value || '— اضغط للتعديل —';
                    valueEl.dataset.value = data.value;
                    const card = el.closest('.product-card');
                    if (field === 'status') {
                        const labels = {'Running':'نشط', 'New Model':'موديل جديد', 'Slow Moving':'بطيء', 'Aging':'قديم'};
                        const badge = card.querySelector('.card-carousel .status-badge');
                        badge.textContent = labels[data.value] || data.value;
                        badge.className = 'status-badge ' + data.value.replaceAll(' ', '-');
                        valueEl.replaceChildren(badge.cloneNode(true));
                    } else if (field === 'code') {
                        const badge = card.querySelector('.code-badge');
                        badge.innerHTML = '<i class="fas fa-hashtag"></i> ';
                        badge.appendChild(document.createTextNode(data.value || '—'));
                    }
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
let enhancementState = null;

window.openLightbox = function(idx, total, productId, images, mediaIds) {
    if (enhancementState?.saving) return;
    if (enhancementState) cancelEnhancement();
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
        <label class="enhancement-profile">نوع التحسين
            <select id="lbEnhanceProfile">
                <option value="whiteware">منتجات بيضاء — تحسين قوي محلي</option>
                <option value="ai4" <?= enhancementAiConfigured('ai4') ? 'selected' : '' ?>>High Resolution ×4 — EDSR <?= enhancementAiConfigured('ai4') ? '(جاهز)' : '(يحتاج تنزيل الموديل)' ?></option>
                <option value="ai" <?= enhancementAiConfigured() && !enhancementAiConfigured('ai4') ? 'selected' : '' ?>>AI ×2 — <?= enhancementAiConfigured() ? 'مُعدّ على السيرفر' : 'يحتاج إعداد Python' ?></option>
            </select>
        </label>
        <button class="lightbox-action-btn lb-enhance" onclick="enhanceImage()"><i class="fas fa-wand-magic-sparkles"></i> تحسين الصورة</button>
        <button class="lightbox-action-btn lb-replace" onclick="triggerReplace()"><i class="fas fa-sync-alt"></i> استبدال</button>
        <button class="lightbox-action-btn lb-delete" onclick="deleteImage()"><i class="fas fa-trash"></i> حذف</button>
        <form class="lightbox-replace-form" id="lbReplaceForm" style="display:none;">
            <input type="file" id="lbReplaceFile" accept="image/*" required>
            <button type="button" onclick="cancelReplace()" style="background:#f0f2f5;border:none;padding:6px 12px;border-radius:8px;cursor:pointer;">إلغاء</button>
        </form>
    `;
    document.getElementById('lbReplaceFile').onchange = function() {
        if (this.files[0]) mediaAction('replace', lbState.mediaIds[lbState.idx], lbState.productId, this.files[0]);
    };
}

window.closeLightbox = function() {
    if (enhancementState?.saving) { showToast('جاري اعتماد التحسين، انتظر لحظة'); return; }
    if (enhancementState) cancelEnhancement();
    document.getElementById('lightbox').classList.remove('active');
    document.body.style.overflow = '';
};

window.lbNav = function(dir) {
    if (enhancementState || !lbState.total) return;
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

// Keep the card and lightbox in sync, including when the lightbox is reopened.
function syncCardMedia(productId, mediaId, path) {
    const card = document.querySelector('.product-card[data-id="' + productId + '"]');
    if (!card) return;
    const image = card.querySelector('[data-media-id="' + mediaId + '"]');
    if (!image) return;
    if (path) { image.src = path; return; }
    image.remove();
    const images = card.querySelectorAll('.lightbox-trigger');
    images.forEach((img, idx) => { img.dataset.idx = idx; });
    const container = card.querySelector('.card-carousel-imgs');
    container.dataset.current = '0';
    container.style.transform = 'translateX(0%)';
    card.querySelector('.media-count').innerHTML = '<i class="fas fa-paperclip"></i> ' + images.length;
    const dots = card.querySelector('.carousel-dots');
    if (dots) {
        dots.innerHTML = Array.from(images, (_, idx) => '<span class="carousel-dot' + (idx === 0 ? ' active' : '') + '"></span>').join('');
    }
    if (images.length <= 1) {
        card.querySelectorAll('.carousel-btn, .carousel-dots').forEach(el => el.remove());
    }
    if (images.length === 0) {
        container.innerHTML = '<div class="no-image"><i class="far fa-image"></i></div>';
    }
}

window.deleteImage = function() {
    if (enhancementState) return;
    if (!confirm('هل تريد حذف هذه الصورة؟')) return;
    const mediaId = lbState.mediaIds[lbState.idx];
    if (!mediaId) { alert('معرف الصورة غير متوفر'); return; }
    
    const fd = new FormData();
    fd.append('csrf', csrfToken);
    fd.append('op', 'delete');
    fd.append('media_id', mediaId);
    const productId = lbState.productId;
    fd.append('product_id', productId);
    
    fetch('?action=media_action', { method:'POST', body: fd })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            syncCardMedia(productId, mediaId);
            const idx = lbState.mediaIds.indexOf(mediaId);
            if (lbState.productId === productId && idx !== -1) {
                lbState.images.splice(idx, 1);
                lbState.mediaIds.splice(idx, 1);
                lbState.total = lbState.images.length;
                lbState.idx = Math.min(lbState.idx, Math.max(0, lbState.total - 1));
                if (lbState.total === 0) closeLightbox();
                else updateLightbox();
            }
            showToast('تم حذف الصورة', 'success');
        } else { showToast(data.error || 'خطأ', 'error'); }
    })
    .catch(() => showToast('خطأ في الاتصال', 'error'));
};

window.mediaAction = function(op, mediaId, productId, file) {
    if (enhancementState) return;
    if (!file) return;
    if (op === 'replace' && !mediaId) { showToast('معرف الصورة غير متوفر', 'error'); return; }
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
                const path = data.path + ts;
                syncCardMedia(productId, mediaId, path);
                const idx = lbState.mediaIds.indexOf(mediaId);
                if (lbState.productId === productId && idx !== -1) {
                    lbState.images[idx] = path;
                    lbState.mediaIds[idx] = Number(data.media_id);
                    updateLightbox();
                }
                showToast('تم استبدال الصورة', 'success');
            }
        } else { showToast(data.error || 'خطأ', 'error'); }
    })
    .catch(() => showToast('خطأ في الاتصال', 'error'));
};

function enhancementRequest(action, fields) {
    const body = new FormData();
    body.append('csrf', csrfToken);
    Object.entries(fields).forEach(([key, value]) => body.append(key, value));
    return fetch('?action=enhancement_' + action, {method: 'POST', body})
        .then(response => response.json())
        .then(data => { if (!data.ok) throw new Error(data.error || 'تعذر تحسين الصورة'); return data; });
}

function enhancementMode(active) {
    document.getElementById('lbImg').style.display = active ? 'none' : '';
    document.getElementById('lbActions').style.display = active ? 'none' : '';
    document.querySelectorAll('#lightbox > .lightbox-nav').forEach(button => { button.style.display = active ? 'none' : ''; });
    document.getElementById('lbEnhancement').hidden = !active;
}

window.cancelEnhancement = function() {
    const state = enhancementState;
    if (!state || state.saving) return;
    enhancementState = null;
    enhancementMode(false);
    document.getElementById('lbEnhancement').replaceChildren();
    if (state.token) enhancementRequest('cancel', {token: state.token}).catch(() => {});
    document.querySelector('.lb-enhance')?.focus();
};

window.enhanceImage = function() {
    if (enhancementState) return;
    const mediaId = lbState.mediaIds[lbState.idx];
    if (!mediaId) { showToast('معرف الصورة غير متوفر', 'error'); return; }
    const profile = document.getElementById('lbEnhanceProfile')?.value || 'whiteware';
    const state = {productId: lbState.productId, mediaId, profile, token: null, ready: false, saving: false};
    enhancementState = state;
    enhancementMode(true);
    const panel = document.getElementById('lbEnhancement');
    panel.innerHTML = '<h2>تحسين الصورة</h2><p role="status">' + (profile === 'ai4' ? 'جاري تشغيل EDSR ×4 لإنشاء نسخة High Resolution. قد يستغرق عدة دقائق على المعالج؛ لا تغلق الصفحة.' : 'جاري تجهيز نسخة محسّنة للمقارنة…') + ' الصورة الأصلية لن تتغير قبل موافقتك.</p><button class="lightbox-action-btn" onclick="cancelEnhancement()">إلغاء والاحتفاظ بالأصل</button>';
    enhancementRequest('create', {product_id: state.productId, media_id: state.mediaId, profile: state.profile})
    .then(data => {
        if (enhancementState !== state) {
            enhancementRequest('cancel', {token: data.token}).catch(() => {});
            return;
        }
        state.token = data.token;
        panel.innerHTML = `
            <h2>هل الصورة المحسّنة أفضل؟</h2>
            <p id="enhancementDescription"></p>
            <div class="enhancement-inspect"><label><input id="enhanceActualSize" type="checkbox" onchange="toggleEnhancementDetail(this.checked)"> فحص التفاصيل بالحجم الفعلي (100٪) — اسحب لرؤية باقي الصورة</label></div>
            <div class="enhancement-compare">
                <figure><figcaption>قبل — الصورة الأصلية</figcaption><div class="enhancement-viewport"><img id="enhanceBefore" alt="الصورة الأصلية قبل التحسين"></div></figure>
                <figure><figcaption>بعد — الصورة المحسّنة</figcaption><div class="enhancement-viewport"><img id="enhanceAfter" alt="معاينة الصورة المحسّنة"></div></figure>
            </div>
            <p id="enhancementStatus" role="status">جاري تحميل المعاينة…</p>
            <div class="enhancement-decisions">
                <a id="enhanceDownload" class="lightbox-action-btn" hidden>تنزيل PNG كامل الدقة — بدون استبدال الأصل</a>
                <button id="enhanceApprove" class="lightbox-action-btn lb-enhance" disabled onclick="approveImageEnhancement()">✓ اعتماد التحسين واستبدال الصورة</button>
                <button id="enhanceCancel" class="lightbox-action-btn" onclick="cancelEnhancement()">إلغاء والاحتفاظ بالأصل</button>
            </div>`;
        const description = document.getElementById('enhancementDescription');
        const dimensions = `الأصل ${data.source_width}×${data.source_height} · النتيجة ${data.width}×${data.height}`;
        description.textContent = data.profile === 'ai4'
            ? `EDSR ×4 — High Resolution: 16 ضعف عدد البكسلات، وPNG بدون فقد بسبب الضغط. ${dimensions}. راجع التفاصيل قبل الاعتماد؛ النموذج يقدّر التفاصيل ولا يضمن استرجاع الأصل المفقود.`
            : data.profile === 'ai'
            ? `نموذج FSRCNN العصبي ×2 مع معالجة للمنتجات البيضاء. ${dimensions}. راجع الحواف والتفاصيل؛ التكبير ليس ضمانًا لاسترجاع المفقود.`
            : `تباين موضعي وإبراز الحواف والظلال مع حماية اللون الأبيض. ${dimensions}. معالجة محلية وليست AI؛ الأبعاد لم تتغير.`;
        let loaded = 0;
        ['enhanceBefore', 'enhanceAfter'].forEach((id, index) => {
            const image = document.getElementById(id);
            image.onload = () => {
                if (enhancementState !== state) return;
                loaded++;
                if (loaded === 2) {
                    state.ready = true;
                    const download = document.getElementById('enhanceDownload');
                    download.href = data.download_url;
                    download.hidden = false;
                    document.getElementById('enhanceApprove').disabled = false;
                    document.getElementById('enhancementStatus').textContent = 'الأصل محفوظ حتى تختار اعتماد التحسين. المعاينة صالحة لمدة 10 دقائق.';
                }
            };
            image.onerror = () => {
                if (enhancementState !== state) return;
                state.ready = false;
                document.getElementById('enhanceApprove').disabled = true;
                document.getElementById('enhancementStatus').textContent = 'تعذر تحميل المعاينة. ألغِ وحاول مرة أخرى؛ الصورة الأصلية لم تتغير.';
            };
            image.src = index === 0 ? data.original_url : data.preview_url;
        });
        document.getElementById('enhanceCancel').focus();
    })
    .catch(error => {
        if (enhancementState !== state) return;
        cancelEnhancement();
        showToast(error.message || 'خطأ في الاتصال', 'error');
    });
};

window.toggleEnhancementDetail = function(actual) {
    document.querySelector('.enhancement-compare')?.classList.toggle('actual-size', actual);
};

window.approveImageEnhancement = function() {
    const state = enhancementState;
    if (!state?.token || !state.ready || state.saving) return;
    state.saving = true;
    document.getElementById('enhanceApprove').disabled = true;
    document.getElementById('enhanceCancel').disabled = true;
    document.getElementById('enhancementStatus').textContent = 'جاري اعتماد التحسين…';
    enhancementRequest('approve', {token: state.token})
    .then(data => {
        const path = data.path + '?t=' + Date.now();
        syncCardMedia(state.productId, state.mediaId, path);
        const idx = lbState.mediaIds.indexOf(state.mediaId);
        if (lbState.productId === state.productId && idx !== -1) lbState.images[idx] = path;
        enhancementState = null;
        enhancementMode(false);
        document.getElementById('lbEnhancement').replaceChildren();
        updateLightbox();
        document.querySelector('.lb-enhance')?.focus();
        showToast('تم اعتماد التحسين واستبدال الصورة', 'success');
    })
    .catch(error => {
        state.saving = false;
        document.getElementById('enhanceApprove').disabled = false;
        document.getElementById('enhanceCancel').disabled = false;
        document.getElementById('enhancementStatus').textContent = error.message || 'تعذر الحفظ. حاول مرة أخرى أو احتفظ بالأصل.';
    });
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

// Handle lightbox triggers
document.addEventListener('click', function(e) {
    const trigger = e.target.closest('.lightbox-trigger');
    if (!trigger) return;
    
    const card = trigger.closest('.product-card');
    const productId = parseInt(trigger.dataset.product);
    const idx = parseInt(trigger.dataset.idx);
    
    // Get all images from this card
    const triggers = Array.from(card.querySelectorAll('.lightbox-trigger'));
    const images = triggers.map(img => img.src);
    const mediaIds = triggers.map(img => Number(img.dataset.mediaId));
    const total = images.length;
    
    if (total > 0) {
        openLightbox(idx, total, productId, images, mediaIds);
    }
});

// ═══ VERIFY SYSTEM ═══
let selectedForVerify = new Set();

window.toggleVerify = function(el, productId) {
    const card = el.closest('.product-card');
    
    const fd = new FormData();
    fd.append('csrf', csrfToken);
    fd.append('id', productId);
    
    fetch('?action=toggle_verify', { method:'POST', body: fd })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            if (data.verified) {
                card.classList.add('verified');
                card.dataset.verified = '1';
                el.innerHTML = '<i class="fas fa-check-circle verified-icon"></i>';
            } else {
                card.classList.remove('verified');
                card.dataset.verified = '0';
                el.innerHTML = '<i class="far fa-circle verify-icon"></i>';
            }
            updateBulkBar();
        } else {
            showToast(data.error || 'خطأ', 'error');
        }
    })
    .catch(() => showToast('خطأ في الاتصال', 'error'));
};

window.bulkVerifySelected = function() {
    if (selectedForVerify.size === 0) return;
    
    const fd = new FormData();
    fd.append('csrf', csrfToken);
    const submittedIds = Array.from(selectedForVerify);
    fd.append('ids', JSON.stringify(submittedIds));
    
    showToast('جاري تأكيد ' + selectedForVerify.size + ' منتج...');
    
    fetch('?action=bulk_verify', { method:'POST', body: fd })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            // Update UI
            submittedIds.forEach(id => {
                const card = document.querySelector('.product-card[data-id="'+id+'"]');
                if (card) {
                    card.classList.add('verified');
                    card.dataset.verified = '1';
                    const icon = card.querySelector('.verify-check');
                    if (icon) icon.innerHTML = '<i class="fas fa-check-circle verified-icon"></i>';
                }
            });
            submittedIds.forEach(id => selectedForVerify.delete(id));
            clearSelectionOutlines(submittedIds);
            updateBulkBar();
            showToast('تم تأكيد ' + data.count + ' منتج بنجاح', 'success');
        } else {
            showToast(data.error || 'خطأ', 'error');
        }
    })
    .catch(() => showToast('خطأ في الاتصال', 'error'));
};

function clearSelectionOutlines(ids) {
    ids.forEach(id => {
        const card = document.querySelector('.product-card[data-id="' + id + '"]');
        if (card) { card.style.outline = ''; card.style.outlineOffset = ''; }
    });
}

window.clearSelection = function() {
    clearSelectionOutlines(selectedForVerify);
    selectedForVerify.clear();
    updateBulkBar();
};

function updateBulkBar() {
    const bar = document.getElementById('bulkVerifyBar');
    const count = document.getElementById('selectedCount');
    if (selectedForVerify.size > 0) {
        bar.classList.add('active');
        count.textContent = selectedForVerify.size;
    } else {
        bar.classList.remove('active');
    }
}

// Allow clicking on card body to toggle selection (not on interactive elements)
document.addEventListener('click', function(e) {
    // Don't trigger if clicking on interactive elements
    if (e.target.closest('.inline-field, .inline-edit-form, .carousel-btn, .upload-btn, .card-btn, .lightbox-trigger, .verify-check')) return;
    
    const card = e.target.closest('.product-card');
    if (!card) return;
    
    const id = parseInt(card.dataset.id);
    if (selectedForVerify.has(id)) {
        selectedForVerify.delete(id);
        card.style.outline = '';
    } else {
        selectedForVerify.add(id);
        card.style.outline = '3px solid #10b981';
        card.style.outlineOffset = '-3px';
    }
    updateBulkBar();
});
</script>
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
    foreach ($previewable as $i => $m) {
        $carouselImgs .= '<img src="'.h($m['path']).'" data-idx="'.$i.'" data-media-id="'.(int)$m['id'].'" data-product="'.$p['id'].'" class="lightbox-trigger">';
        $carouselDots .= '<span class="carousel-dot'.($i===0?' active':'').'"></span>';
    }
    
    $statusClass = str_replace(' ', '-', $p['status']);
    $statusText = ['Running'=>'نشط','New Model'=>'موديل جديد','Slow Moving'=>'بطيء','Aging'=>'قديم'][$p['status']] ?? $p['status'];
    $isVerified = (int)($p['verified'] ?? 0) === 1;
    $verifiedClass = $isVerified ? ' verified' : '';
    
    $carouselBtns = count($previewable) > 1 ? '
        <button class="carousel-btn carousel-prev" onclick="event.stopPropagation();carouselPrev(this)"><i class="fas fa-chevron-right"></i></button>
        <button class="carousel-btn carousel-next" onclick="event.stopPropagation();carouselNext(this)"><i class="fas fa-chevron-left"></i></button>
        <div class="carousel-dots">'.$carouselDots.'</div>
    ' : '';
    
    $noImage = empty($previewable) ? '<div class="no-image"><i class="far fa-image"></i></div>' : '';
    
    $verifiedIcon = $isVerified ? '<i class="fas fa-check-circle verified-icon"></i>' : '<i class="far fa-circle verify-icon"></i>';
    
    return '<div class="product-card'.$verifiedClass.'" data-id="'.$p['id'].'" data-version="'.$p['version'].'" data-verified="'.($isVerified?'1':'0').'">'
        .'<div class="card-carousel">'
        .'<div class="card-carousel-imgs">'.$carouselImgs.$noImage.'</div>'
        .$carouselBtns
        .'<span class="status-badge '.$statusClass.'">'.$statusText.'</span>'
        .'<span class="code-badge"><i class="fas fa-hashtag"></i> '.h($p['code']?:'—').'</span>'
        .'<span class="media-count"><i class="fas fa-paperclip"></i> '.count($previewable).'</span>'
        .'<div class="verify-check" onclick="event.stopPropagation();toggleVerify(this,'.$p['id'].')" title="تم التأكد">'.$verifiedIcon.'</div>'
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
function productAction(action, id, message) {
    if (!confirm(message)) return;
    const fd = new FormData();
    fd.append('csrf', csrfToken);
    fd.append('id', id);
    fetch('?action=' + action, { method:'POST', body: fd })
    .then(r => r.json())
    .then(data => {
        if (data.ok) location.reload();
        else showToast(data.error || 'تعذر تنفيذ العملية', 'error');
    })
    .catch(() => showToast('خطأ في الاتصال، حاول مرة أخرى', 'error'));
}
function archiveProduct(id) {
    productAction('archive', id, 'هل تريد أرشفة هذا المنتج؟');
}
function restoreProduct(id) {
    productAction('restore', id, 'هل تريد استعادة هذا المنتج؟');
}
function deleteProduct(id) {
    productAction('delete', id, 'هل تريد حذف هذا المنتج نهائياً مع مرفقاته؟');
}
</script>
<?php

// ── HOME ──────────────────────────────────────────────────
function showHome(int $page): void {
    $d = db();
    $allProducts = allProducts($d, false);
    $groups = [];
    foreach ($allProducts as $p) {
        $key = $p['group'];
        $groups[$key] = ($groups[$key] ?? 0) + 1;
    }
    arsort($groups);
    
    $filtered = array_values($allProducts);
    
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
    </div>
    
    <?php renderProducts($paged, $page, $totalPages, '?action=home'); ?>
    <?php
    
    layout('الرئيسية', ob_get_clean(), $groups, '', 'home');
}

function showSettings(): void {
    $d = db();
    ob_start();
    ?>
    <form method="post" action="?action=save_settings" class="toolbar" style="align-items:stretch;flex-direction:column">
        <h2>الإعدادات</h2>
        <?= token() ?>
        <label>عنوان الموقع <input class="toolbar-search" name="site_title" required maxlength="190" value="<?= h(setting($d, 'site_title', 'LUFLY Catalog')) ?>"></label>
        <label>اللون الأساسي <input type="color" name="primary_color" value="<?= h(setting($d, 'primary_color', '#1F9E9E')) ?>"></label>
        <label>اللون الثانوي <input type="color" name="secondary_color" value="<?= h(setting($d, 'secondary_color', '#147070')) ?>"></label>
        <button type="submit" class="card-btn">حفظ الإعدادات</button>
    </form>
    <?php
    layout('الإعدادات', ob_get_clean(), getGroups($d), '', 'settings');
}

function showArchive(int $page): void {
    $d = db();
    $products = allProducts($d, true);
    $totalPages = max(1, (int)ceil(count($products) / 12));
    $page = max(1, min($page, $totalPages));
    ob_start();
    echo '<div class="toolbar"><div class="toolbar-title">الأرشيف</div><div class="toolbar-count">'.count($products).' منتج</div></div>';
    if (!$products) echo '<p>لا توجد منتجات في الأرشيف</p>';
    foreach (array_slice($products, ($page - 1) * 12, 12) as $p) {
        echo '<div class="toolbar"><strong>'.h($p['code']).'</strong><span>'.h($p['description']).'</span>'
            .'<button class="card-btn" onclick="restoreProduct('.(int)$p['id'].')">استعادة</button>'
            .'<button class="card-btn danger" onclick="deleteProduct('.(int)$p['id'].')">حذف نهائي</button></div>';
    }
    if ($page > 1) echo '<a class="card-btn" href="?action=archive_list&page='.($page - 1).'">السابق</a>';
    if ($page < $totalPages) echo '<a class="card-btn" href="?action=archive_list&page='.($page + 1).'">التالي</a>';
    layout('الأرشيف', ob_get_clean(), getGroups($d), '', 'archive_list');
}

// ─── GROUP VIEW ────────────────────────────────────────────
function showGroup(string $prefix, int $page): void {
    $d = db();
    $allProducts = allProducts($d, false);
    $filtered = array_values(array_filter($allProducts, fn($p) => $p['group'] === $prefix));
    
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
    
    <?php renderProducts($paged, $page, $totalPages, '?action=group&prefix='.urlencode((string)$prefix)); ?>
    <?php
    
    $groups = getGroups($d);
    layout('مجموعة '.$prefix, ob_get_clean(), $groups, '', 'group');
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
    layout('بحث: '.$q, ob_get_clean(), $groups, '', 'search');
}

// ─── ERROR PAGES ───────────────────────────────────────────
function showError(string $message): void {
    ob_start();
    ?><div class="error-box"><h3><i class="fas fa-exclamation-circle"></i> حدث خطأ</h3><p><?= h($message) ?></p><a href="?action=home" class="btn btn-outline" style="margin-top:16px;">العودة للرئيسية</a></div><?php
    layout('خطأ', ob_get_clean(), [], '', 'home');
}

function showSetupError(string $message): void {
    global $config;
    $fileMode = $config['driver'] === 'json';
    ob_start();
    ?>
    <div class="error-box">
        <h3><i class="fas fa-exclamation-triangle"></i> <?= $fileMode ? 'تعذر فتح البيانات المحلية' : 'تعذر الاتصال بقاعدة البيانات' ?></h3>
        <p style="margin:12px 0;"><?= h($message) ?></p>
        <div style="text-align:right;margin-top:20px;padding:16px;background:rgba(0,0,0,0.05);border-radius:8px;">
            <h4 style="margin-bottom:10px;">خطوات الحل:</h4>
            <ol style="padding-right:20px;line-height:2;">
                <?php if ($fileMode): ?>
                <li>راجع صلاحيات الكتابة في data/local.</li>
                <li>لو ملف JSON تالف، احتفظ به وراجع النسخة الاحتياطية قبل أي استرجاع.</li>
                <?php else: ?>
                <li>افتح <strong>XAMPP Control Panel</strong></li>
                <li>تأكد إن <strong>MySQL شغال</strong> (لو مش شغال اضغط Start)</li>
                <li>بعد ما MySQL يشتغل، حدّث الصفحة (F5)</li>
                <?php endif; ?>
            </ol>
        </div>
        <div style="margin-top:20px;">
            <?php if (!$fileMode): ?><a href="setup.php" class="btn btn-primary" style="margin:4px;"><i class="fas fa-stethoscope"></i> فحص النظام</a><?php endif; ?>
            <a href="?action=home" class="btn btn-outline" style="margin:4px;"><i class="fas fa-sync"></i> تحديث</a>
        </div>
    </div>
    <?php
    layout('خطأ في الإعداد', ob_get_clean(), [], '', 'home');
}
