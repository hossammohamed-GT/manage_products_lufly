<?php
/**
 * تشخيص مشكلة CSRF/Session
 * افتحه من المتصفح: https://lufly.tr/public/manage_products_lufly/test_csrf.php
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>تشخيص CSRF</title>";
echo "<style>body{font-family:Arial,sans-serif;direction:rtl;padding:20px;background:#f5f5f5}";
echo ".box{background:white;padding:20px;margin:20px 0;border-radius:8px;box-shadow:0 2px 4px rgba(0,0,0,0.1)}";
echo ".ok{color:#28a745}.err{color:#dc3545}.warn{color:#856404}";
echo "table{width:100%;border-collapse:collapse;margin:10px 0}td,th{padding:8px;border:1px solid #ddd;text-align:right}th{background:#f8f9fa}";
echo "pre{background:#f8f9fa;padding:10px;border-radius:5px;direction:ltr;text-align:left}";
echo "</style></head><body>";

echo "<h1>🔍 تشخيص CSRF & Session</h1>";

echo "<div class='box'><h2>1️ معلومات الـ Request</h2>";
echo "<table>";
echo "<tr><th>Property</th><th>Value</th></tr>";
echo "<tr><td>SCRIPT_NAME</td><td>{$_SERVER['SCRIPT_NAME']}</td></tr>";
echo "<tr><td>REQUEST_URI</td><td>{$_SERVER['REQUEST_URI']}</td></tr>";
echo "<tr><td>DOCUMENT_ROOT</td><td>{$_SERVER['DOCUMENT_ROOT']}</td></tr>";
echo "<tr><td>HTTPS</td><td>{$_SERVER['HTTPS'] ?? 'not set'}</td></tr>";
echo "<tr><td>PHP_SELF</td><td>{$_SERVER['PHP_SELF'] ?? 'N/A'}</td></tr>";
echo "</table></div>";

echo "<div class='box'><h2>2️ إعدادات Session</h2>";
echo "<pre>";
echo "session.cookie_lifetime = " . ini_get('session.cookie_lifetime') . "\n";
echo "session.cookie_path = " . ini_get('session.cookie_path') . "\n";
echo "session.cookie_domain = " . (ini_get('session.cookie_domain') ?: '(empty)') . "\n";
echo "session.cookie_secure = " . (ini_get('session.cookie_secure') ?: '0') . "\n";
echo "session.cookie_httponly = " . (ini_get('session.cookie_httponly') ?: '0') . "\n";
echo "session.cookie_samesite = " . (ini_get('session.cookie_samesite') ?: '(empty)') . "\n";
echo "session.use_cookies = " . ini_get('session.use_cookies') . "\n";
echo "session.use_strict_mode = " . ini_get('session.use_strict_mode') . "\n";
echo "</pre></div>";

echo "<div class='box'><h2>3️ محتوى .env</h2><pre>";
echo htmlspecialchars(file_get_contents(__DIR__.'/.env'));
echo "</pre></div>";

// الآن نبدأ session ونشوف CSRF
require_once __DIR__.'/app.php';

echo "<div class='box'><h2>4️ بعد session_start()</h2>";
echo "<pre>";
echo "session_id: " . session_id() . "\n";
echo "session_name: " . session_name() . "\n";
echo "CSRF token: " . csrf() . "\n";
echo "session cookie params: ";
print_r(session_get_cookie_params());
echo "</pre></div>";

echo "<div class='box'><h2>5️ تحليل المشكلة</h2>";

$cookieParams = session_get_cookie_params();
$scriptPath = dirname($_SERVER['SCRIPT_NAME']);

echo "<table>";
echo "<tr><th>الفحص</th><th>النتيجة</th><th>التوصية</th></tr>";

// فحص 1: cookie path
$problem = false;
if ($cookieParams['path'] === '/') {
    echo "<tr><td>Cookie Path</td><td class='ok'>✅ /</td><td>صحيح</td></tr>";
} else {
    echo "<tr><td>Cookie Path</td><td class='warn'>⚠️ {$cookieParams['path']}</td><td>ممكن يكون غلط!</td></tr>";
    $problem = true;
}

// فحص 2: cookie secure
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    if ($cookieParams['secure']) {
        echo "<tr><td>Cookie Secure</td><td class='ok'>✅ نعم</td><td>صحيح (HTTPS)</td></tr>";
    } else {
        echo "<tr><td>Cookie Secure</td><td class='warn'>⚠️ لا</td><td>يفضل Secure على HTTPS</td></tr>";
    }
} else {
    echo "<tr><td>HTTPS</td><td class='warn'>⚠️ غير نشط</td><td>الموقع على HTTP</td></tr>";
}

// فحص 3: SameSite
echo "<tr><td>SameSite</td><td>{$cookieParams['samesite'] ?? 'Lax'}</td><td>Lax = مناسب</td></tr>";

echo "</table>";

echo "<br><p><b> المشكلة الأرجح:</b></p>";
echo "<p>الـ <code>session.cookie_path</code> في app.php مش محدد، فـ PHP بيستخدم المسار الفعلي للـ script:</p>";
echo "<pre>{$scriptPath}</pre>";
echo "<p>لكن الـ cookie بيتبعت على المسار ده فقط. لو الصفحة بتُطلب من مسار مختلف (عبر rewrite)، الـ cookie مش هيوصل!</p>";

echo "<br><p><b>✅ الحل:</b> نحدد <code>cookie_path</code> في <code>session_set_cookie_params</code> يدوياً</p>";
echo "</div>";

echo "<div class='box'><h2>6️ اختبار POST مباشر</h2>";
echo "<form method='POST' action=''>";
echo "<input type='hidden' name='test_csrf' value='1'>";
echo "<input type='hidden' name='csrf' value='" . csrf() . "'>";
echo "<button type='submit' style='padding:10px 20px;background:#1F9E9E;color:white;border:none;border-radius:5px;cursor:pointer'>🧪 اختبر CSRF POST</button>";
echo "</form>";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_csrf'])) {
    echo "<br><h3>نتيجة الاختبار:</h3>";
    require_once __DIR__.'/app.php';
    try {
        checkCsrf();
        echo "<p class='ok'>✅ CSRF Token صحيح!</p>";
    } catch (Exception $e) {
        echo "<p class='err'>❌ CSRF Token فشل: {$e->getMessage()}</p>";
    }
}
echo "</div>";

echo "</body></html>";
?>