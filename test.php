<?php
// LUFLY - Simple Test Page
// افتح: http://localhost/manage_products_lufly/test.php

echo "<h1>✅ PHP is working!</h1>";
echo "<p>PHP Version: " . phpversion() . "</p>";
echo "<p>Server: " . $_SERVER['SERVER_SOFTWARE'] . "</p>";
echo "<hr>";

// Check extensions
$extensions = ['pdo', 'pdo_mysql', 'fileinfo', 'json', 'session'];
echo "<h2>Extensions:</h2><ul>";
foreach ($extensions as $ext) {
    $loaded = extension_loaded($ext);
    echo "<li>" . $ext . ": " . ($loaded ? "✅ LOADED" : "❌ MISSING") . "</li>";
}
echo "</ul>";

// Check MySQL connection
echo "<h2>MySQL Connection:</h2>";
try {
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306', 'root', '');
    echo "<p>✅ Connected to MySQL server</p>";
    
    // Try to create/use database
    $pdo->exec('CREATE DATABASE IF NOT EXISTS lufly_catalog CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    echo "<p>✅ Database 'lufly_catalog' ready</p>";
    
    // Check if tables exist
    $tables = $pdo->query('SHOW TABLES FROM lufly_catalog')->fetchAll(PDO::FETCH_COLUMN);
    echo "<p>Tables in lufly_catalog: " . (empty($tables) ? "none yet" : implode(", ", $tables)) . "</p>";
    
} catch (Exception $e) {
    echo "<p>❌ MySQL Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>Make sure MySQL is running in XAMPP Control Panel</p>";
}

// Check if index.php exists
echo "<h2>Files Check:</h2>";
$files = ['index.php', 'app.php', 'config.php', 'data/catalog.json'];
echo "<ul>";
foreach ($files as $f) {
    $exists = file_exists(__DIR__ . '/' . $f);
    echo "<li>$f: " . ($exists ? "✅" : "❌") . "</li>";
}
echo "</ul>";

// Check uploads directory
$uploadsDir = __DIR__ . '/uploads';
echo "<p>Uploads dir writable: " . (is_writable($uploadsDir) ? "✅" : "❌ - Run: chmod 755 uploads/") . "</p>";

echo "<hr>";
echo "<h2>Next Steps:</h2>";
echo "<p>If everything above shows ✅, open: <a href='index.php'>index.php</a></p>";
echo "<p>If MySQL is not connected, start MySQL from XAMPP Control Panel.</p>";
echo "<p>If extensions are missing, edit C:\\xampp\\php\\php.ini and uncomment them.</p>";
