<?php
// LUFLY Catalog Configuration
// SQLite is the default (works without MySQL service)
// Change 'driver' to 'mysql' if MySQL is available and preferred.
return [
    'driver' => 'sqlite',  // 'sqlite' works immediately, 'mysql' needs MySQL service running
    'host' => '127.0.0.1', 'port' => 3306,
    'database' => 'lufly_catalog', 'username' => 'root', 'password' => '',
    'sqlite_path' => __DIR__ . '/data/lufly.sqlite',
    'logo_path' => 'assets/logo.png',
];
