<?php
// LUFLY - Super simple PHP test (NO MySQL)
// If this works, PHP is fine. The issue is MySQL connection.
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head><meta charset="UTF-8"><title>PHP Test</title></head>
<body>
<h1>✅ PHP شغال تمام!</h1>
<p>PHP Version: <?php echo phpversion(); ?></p>
<p>Server: <?php echo $_SERVER['SERVER_SOFTWARE']; ?></p>
<hr>
<h2>Extensions:</h2>
<ul>
<li>pdo: <?php echo extension_loaded('pdo') ? '✅' : '❌'; ?></li>
<li>pdo_mysql: <?php echo extension_loaded('pdo_mysql') ? '✅' : '❌'; ?></li>
<li>fileinfo: <?php echo extension_loaded('fileinfo') ? '✅' : ''; ?></li>
<li>json: <?php echo extension_loaded('json') ? '✅' : '❌'; ?></li>
<li>session: <?php echo extension_loaded('session') ? '✅' : '❌'; ?></li>
</ul>
<hr>
<p>لو شفت الصفحة دي، PHP شغال 100%. المشكلة بس في MySQL.</p>
<p><a href="hello.html">← Apache test (HTML)</a></p>
<p><a href="index.php">← LUFLY Catalog</a></p>
</body>
</html>
