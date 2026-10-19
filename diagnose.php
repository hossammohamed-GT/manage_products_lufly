<?php
/**
 * ملف تشخيص لمعرفة كيف التطبيق بيتصل بالـ database
 * افتحه من المتصفح: https://lufly.tr/public/manage_products_lufly/diagnose.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>تشخيص Database</title>";
echo "<style>body{font-family:Arial,sans-serif;direction:rtl;padding:20px;background:#f5f5f5}";
echo ".box{background:white;padding:20px;margin:20px 0;border-radius:8px;box-shadow:0 2px 4px rgba(0,0,0,0.1)}";
echo ".ok{color:#28a745}.err{color:#dc3545}.warn{color:#ffc107}";
echo "table{width:100%;border-collapse:collapse;margin:10px 0}td,th{padding:8px;border:1px solid #ddd;text-align:right}";
echo "th{background:#f8f9fa}</style></head><body>";

echo "<h1>🔍 تشخيص قاعدة البيانات</h1>";

// 1️ تحميل الإعدادات
echo "<div class='box'><h2>1️ إعدادات الاتصال (config.php)</h2>";
try {
  $config = require __DIR__ . '/config.php';
  echo "<table>";
  foreach ($config as $key => $value) {
    $display = $value;
    if (is_string($value) && strlen($value) > 50)
      $display = substr($value, 0, 50) . '...';
    if ($key === 'password')
      $display = $value ? '****' : '(فارغ)';
    echo "<tr><td><b>{$key}</b></td><td>{$display}</td></tr>";
  }
  echo "</table>";
} catch (Exception $e) {
  echo "<p class='err'>❌ خطأ في تحميل config.php: " . $e->getMessage() . "</p>";
}
echo "</div>";

// 2️ محتوى .env
echo "<div class='box'><h2>2️ محتوى ملف .env</h2>";
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
  echo "<pre style='background:#f8f9fa;padding:15px;border-radius:5px'>";
  echo htmlspecialchars(file_get_contents($envFile));
  echo "</pre>";
} else {
  echo "<p class='warn'>⚠️ ملف .env مش موجود!</p>";
}
echo "</div>";

// 3️ اختبار الاتصال
echo "<div class='box'><h2>3️ اختبار الاتصال</h2>";

// اختبار MySQL
echo "<h3>🔌 MySQL</h3>";
try {
  $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']};charset=utf8mb4";
  $pdo = new PDO($dsn, $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
  echo "<p class='ok'>✅ تم الاتصال بـ MySQL بنجاح</p>";

  $count = $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
  echo "<p>📊 عدد المنتجات في MySQL: <b>{$count}</b></p>";

  // اختبار الكتابة
  echo "<p>🧪 جاري اختبار الكتابة...</p>";
  $testCode = 'TEST_' . time();
  $stmt = $pdo->prepare('INSERT INTO products (code, brand, status, description, notes, source_file, source_row, source_extra, verified, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, NOW(), NOW())');
  $stmt->execute([$testCode, 'lufly', 'Running', 'اختبار', '', '', 0, '']);
  $newId = $pdo->lastInsertId();
  echo "<p class='ok'>✅ تم INSERT بنجاح (ID: {$newId})</p>";

  $check = $pdo->prepare('SELECT id, code FROM products WHERE id = ?');
  $check->execute([$newId]);
  $row = $check->fetch();
  if ($row) {
    echo "<p class='ok'>✅ تم SELECT بعد INSERT: ID={$row['id']}, Code={$row['code']}</p>";
  }

  // حذف الاختبار
  $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$newId]);
  echo "<p class='ok'>✅ تم حذف السجل التجريبي</p>";

} catch (PDOException $e) {
  echo "<p class='err'>❌ فشل الاتصال بـ MySQL: " . $e->getMessage() . "</p>";
}

// اختبار SQLite
echo "<h3>🔌 SQLite</h3>";
$sqlitePath = $config['sqlite_path'];
if (file_exists($sqlitePath)) {
  echo "<p class='warn'>⚠️ ملف SQLite موجود: {$sqlitePath}</p>";
  echo "<p>الحجم: " . round(filesize($sqlitePath) / 1024, 2) . " KB</p>";

  try {
    $sqlitePdo = new PDO('sqlite:' . $sqlitePath);
    $count = $sqlitePdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
    echo "<p>📊 عدد المنتجات في SQLite: <b>{$count}</b></p>";

    if ($count > 0) {
      echo "<p class='warn'>⚠️ SQLite فيه بيانات! ده ممكن يكون سبب المشكلة</p>";
      echo "<p>التطبيق ممكن بيقرأ من MySQL وبيكتب في SQLite!</p>";
    }
  } catch (PDOException $e) {
    echo "<p class='err'>❌ خطأ في SQLite: " . $e->getMessage() . "</p>";
  }
} else {
  echo "<p class='ok'>✅ ملف SQLite مش موجود (ممتاز)</p>";
}
echo "</div>";

// 4️ التوصية
echo "<div class='box'><h2>4️ التوصية</h2>";
echo "<p>المشكلة المحتملة:</p>";
echo "<ol>";
echo "<li>التطبيق بيستخدم <b>MySQL للقراءة</b> (عشان التعديل من phpMyAdmin بيظهر)</li>";
echo "<li>لكن بيستخدم <b>SQLite للكتابة</b> (عشان التعديل من الموقع مش بيظهر في MySQL)</li>";
echo "</ol>";
echo "<p><b>الحل:</b> تأكد إن ملف <code>.env</code> فيه الإعدادات الصح، وإن الـ driver في config.php = <code>mysql</code></p>";
echo "</div>";

echo "</body></html>";
?>