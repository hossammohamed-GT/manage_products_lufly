<?php
// LUFLY - Standalone Setup & Test
// This file doesn't require MySQL to work
// Open: http://localhost/manage_products_lufly/setup.php

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title>LUFLY - Setup Check</title>
<style>
body { font-family: 'Segoe UI', Arial, sans-serif; max-width: 800px; margin: 40px auto; padding: 20px; background: #f0f7f6; }
.card { background: white; padding: 24px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); margin-bottom: 20px; }
h1 { color: #1F9E9E; margin-bottom: 24px; }
h2 { color: #147070; margin-top: 24px; margin-bottom: 12px; }
.ok { color: #10b981; font-weight: bold; }
.err { color: #ef4444; font-weight: bold; }
.warn { color: #f59e0b; font-weight: bold; }
ul { list-style: none; padding: 0; }
li { padding: 8px 0; border-bottom: 1px solid #eee; }
li:last-child { border-bottom: none; }
.btn { display: inline-block; padding: 12px 24px; background: #1F9E9E; color: white; text-decoration: none; border-radius: 8px; margin: 8px 4px; font-weight: bold; }
.btn:hover { background: #147070; }
pre { background: #1e1e2e; color: #a6e3a1; padding: 16px; border-radius: 8px; overflow-x: auto; font-size: 13px; }
code { background: #f0f0f0; padding: 2px 6px; border-radius: 4px; font-family: monospace; }
</style>
</head>
<body>

<div class="card">
    <h1>🔧 LUFLY Catalog - فحص النظام</h1>
    <p>صفحة الفحص دي هتساعدك تعرف إيه المشكلة لو الموقع مش شغال.</p>
</div>

<?php
$allOk = true;

// 1. PHP Version
echo '<div class="card"><h2>1️⃣ PHP Version</h2><ul>';
$phpVersion = phpversion();
$phpOk = version_compare($phpVersion, '8.1.0', '>=');
echo '<li>PHP Version: <span class="' . ($phpOk ? 'ok' : 'err') . '">' . $phpVersion . '</span>';
if (!$phpOk) echo ' <span class="warn">(مطلوب 8.1+)</span>';
echo '</li></ul></div>';
if (!$phpOk) $allOk = false;

// 2. Extensions
echo '<div class="card"><h2>2️ PHP Extensions</h2><ul>';
$required = [
    'pdo' => 'PDO (قاعدة البيانات)',
    'pdo_mysql' => 'PDO MySQL (اتصال MySQL)',
    'fileinfo' => 'FileInfo (رفع الصور)',
    'json' => 'JSON (معالجة البيانات)',
    'session' => 'Session (حفظ الجلسات)',
];
foreach ($required as $ext => $desc) {
    $loaded = extension_loaded($ext);
    echo '<li>' . $desc . ' (' . $ext . '): <span class="' . ($loaded ? 'ok' : 'err') . '">' . ($loaded ? '✅ مفعّلة' : '❌ مش مفعّلة') . '</span>';
    if (!$loaded) {
        echo '<br><small>حل: افتح <code>C:\\xampp\\php\\php.ini</code> وابحث عن <code>;extension=' . $ext . '</code> وامسح <code>;</code> من أول السطر</small>';
        $allOk = false;
    }
    echo '</li>';
}
echo '</ul></div>';

// 3. Files Check
echo '<div class="card"><h2>3️ ملفات المشروع</h2><ul>';
$files = [
    'index.php' => 'الصفحة الرئيسية',
    'app.php' => 'Backend functions',
    'config.php' => 'إعدادات الاتصال',
    'data/catalog.json' => 'بيانات المنتجات',
    'assets/logo.png' => 'اللوجو',
];
foreach ($files as $f => $desc) {
    $exists = file_exists(__DIR__ . '/' . $f);
    echo '<li>' . $desc . ' (' . $f . '): <span class="' . ($exists ? 'ok' : 'err') . '">' . ($exists ? '✅ موجود' : '❌ مش موجود') . '</span></li>';
    if (!$exists) $allOk = false;
}
echo '</ul></div>';

// 4. Directory Permissions
echo '<div class="card"><h2>4️⃣ صلاحيات المجلدات</h2><ul>';
$dirs = ['uploads', 'data', 'assets/catalog'];
foreach ($dirs as $d) {
    $writable = is_writable(__DIR__ . '/' . $d);
    echo '<li>' . $d . ': <span class="' . ($writable ? 'ok' : 'warn') . '">' . ($writable ? '✅ قابل للكتابة' : '⚠️ مش قابل للكتابة') . '</span></li>';
}
echo '</ul></div>';

// 5. MySQL Check
echo '<div class="card"><h2>5️⃣ MySQL Connection</h2><ul>';
try {
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306', 'root', '', [PDO::ATTR_TIMEOUT => 3]);
    echo '<li>اتصال MySQL: <span class="ok">✅ نجح</span></li>';
    
    // Check database
    $pdo->exec('CREATE DATABASE IF NOT EXISTS lufly_catalog CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    echo '<li>قاعدة البيانات lufly_catalog: <span class="ok">✅ موجودة</span></li>';
    
    // Check tables
    $tables = $pdo->query('SHOW TABLES FROM lufly_catalog')->fetchAll(PDO::FETCH_COLUMN);
    if (empty($tables)) {
        echo '<li>الجداول: <span class="warn">⚠️ فاضية - هتتعمل تلقائياً عند أول تشغيل</span></li>';
    } else {
        echo '<li>الجداول: <span class="ok">✅ ' . implode(', ', $tables) . '</span></li>';
        $count = (int)$pdo->query('SELECT COUNT(*) FROM lufly_catalog.products')->fetchColumn();
        echo '<li>عدد المنتجات: <span class="ok">' . $count . '</span></li>';
    }
} catch (Exception $e) {
    echo '<li>اتصال MySQL: <span class="err">❌ فشل</span></li>';
    echo '<li>الخطأ: ' . htmlspecialchars($e->getMessage()) . '</li>';
    echo '<li><small>تأكد إن MySQL شغال في XAMPP Control Panel</small></li>';
    $allOk = false;
}
echo '</ul></div>';

// Summary
echo '<div class="card">';
if ($allOk) {
    echo '<h2 style="color:#10b981;">🎉 كل حاجة تمام!</h2>';
    echo '<p>النظام جاهز. افتح الصفحة الرئيسية:</p>';
    echo '<a href="index.php" class="btn">🏠 افتح LUFLY Catalog</a>';
} else {
    echo '<h2 style="color:#ef4444;">️ فيه مشاكل لازم تتحل</h2>';
    echo '<p>راجع الأخطاء اللي فوق وحلها، ثم أعد تحميل الصفحة.</p>';
}
echo '</div>';

// Quick links
echo '<div class="card"><h2>🔗 روابط سريعة</h2><ul>';
echo '<li><a href="index.php" class="btn">الموقع الرئيسي</a></li>';
echo '<li><a href="index.php?action=settings" class="btn">الإعدادات</a></li>';
echo '</ul></div>';
?>

</body>
</html>
