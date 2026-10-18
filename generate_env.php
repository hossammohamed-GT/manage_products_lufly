<?php
/**
 * LUFLY Catalog - Environment Setup Helper
 * Run this once to create your .env file with the correct settings.
 * 
 * Usage: php generate_env.php
 * 
 * Or just create a .env file manually in the project root with:
 * DB_HOST=127.0.0.1
 * DB_PORT=3306
 * DB_DATABASE=lufly_catalog
 * DB_USERNAME=root
 * DB_PASSWORD=
 * APP_PASSWORD=YourPasswordHere
 */

$envFile = __DIR__ . '/.env';

if (is_file($envFile)) {
    echo "✓ .env file already exists.\n";
    echo "  If you want to recreate it, delete it first and run this script again.\n";
    exit(0);
}

// Default configuration
$defaults = [
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '3306',
    'DB_DATABASE' => 'lufly_catalog',
    'DB_USERNAME' => 'root',
    'DB_PASSWORD' => '',
    'APP_PASSWORD' => 'admin123',
];

// Create .env file
$lines = [];
foreach ($defaults as $key => $value) {
    $lines[] = "$key=$value";
}
$content = implode("\n", $lines) . "\n";

if (file_put_contents($envFile, $content) !== false) {
    echo "✓ Created .env file successfully!\n\n";
    echo "Default settings:\n";
    echo "  DB_HOST=127.0.0.1\n";
    echo "  DB_PORT=3306\n";
    echo "  DB_DATABASE=lufly_catalog\n";
    echo "  DB_USERNAME=root\n";
    echo "  DB_PASSWORD=\n";
    echo "  APP_PASSWORD=admin123\n\n";
    echo "⚠ IMPORTANT: Edit the .env file and change APP_PASSWORD to your desired password!\n";
    echo "  Also set DB_PASSWORD if your MySQL has a password.\n";
    echo "  NEVER commit .env to git (it's already in .gitignore).\n";
} else {
    echo "✗ Failed to create .env file. Check file permissions.\n";
    exit(1);
}
