<?php
// Copy to config.local.php to override. XAMPP defaults: root, empty password.
return [
    'driver' => 'mysql', // sqlite is available only as an optional local demo/test mode
    'host' => '127.0.0.1', 'port' => 3306,
    'database' => 'lufly_catalog', 'username' => 'root', 'password' => '',
    'sqlite_path' => __DIR__ . '/data/demo.sqlite',
];
