<?php
// LUFLY Catalog Configuration
// Reads from .env file (auto-generated from .env.example if missing)

$envFile = __DIR__ . '/.env';

// Auto-generate .env from .env.example if it doesn't exist
if (!is_file($envFile)) {
    $example = __DIR__ . '/.env.example';
    if (is_file($example)) {
        $exampleContent = file_get_contents($example);
        // Remove comment lines and empty lines for a cleaner env file
        $lines = explode("\n", $exampleContent);
        $cleanLines = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed !== '' && strpos($trimmed, '#') !== 0) {
                $cleanLines[] = $line;
            }
        }
        // Always set a default APP_PASSWORD so login is never open
        $cleanContent = implode("\n", $cleanLines);
        if (strpos($cleanContent, 'APP_PASSWORD=') === false) {
            $cleanContent .= "\nAPP_PASSWORD=admin123";
        }
        file_put_contents($envFile, $cleanContent . "\n");
    } else {
        // Fallback: create minimal .env
        file_put_contents($envFile, "DB_HOST=127.0.0.1\nDB_PORT=3306\nDB_DATABASE=lufly_catalog\nDB_USERNAME=root\nDB_PASSWORD=\nAPP_PASSWORD=admin123\n");
    }
}

// Parse .env file
$env = [];
$lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') continue;
    if (strpos($line, '=') !== false) {
        list($key, $value) = explode('=', $line, 2);
        $env[trim($key)] = trim($value);
    }
}

return [
    'host' => $env['DB_HOST'] ?? '127.0.0.1',
    'port' => (int)($env['DB_PORT'] ?? 3306),
    'database' => $env['DB_DATABASE'] ?? 'lufly_catalog',
    'username' => $env['DB_USERNAME'] ?? 'root',
    'password' => $env['DB_PASSWORD'] ?? '',
    'app_password' => $env['APP_PASSWORD'] ?? '',
    'logo_path' => 'assets/logo.png',
];
