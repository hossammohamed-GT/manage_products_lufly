<?php
// LUFLY Catalog Configuration
// Reads from .env file (DO NOT commit .env to git)

$env = [];
if (is_file(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $env[trim($key)] = trim($value);
        }
    }
}

return [
    'driver' => 'mysql',
    'host' => $env['DB_HOST'] ?? '127.0.0.1',
    'port' => (int)($env['DB_PORT'] ?? 3306),
    'database' => $env['DB_DATABASE'] ?? 'lufly_catalog',
    'username' => $env['DB_USERNAME'] ?? '',
    'password' => $env['DB_PASSWORD'] ?? '',
    'sqlite_path' => __DIR__ . '/data/lufly.sqlite',
    'app_password' => $env['APP_PASSWORD'] ?? '',
    'logo_path' => 'assets/logo.png',
];
