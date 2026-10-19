<?php
// Trusted local CLI setup only: never expose configuration changes through HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$temp = null;
$exitCode = 0;
try {
    if ($argc !== 5) throw new RuntimeException('Usage: php setup_config.php PROJECT PYTHON MODEL BACKUP_DIRECTORY');
    [, $project, $python, $model, $backupDirectory] = $argv;
    $project = realpath($project);
    if (!$project || !is_file($project . '/index.php') || !is_file($python) || !is_file($model))
        throw new RuntimeException('Project, Python or model path is invalid.');
    $manifest = json_decode(file_get_contents(__DIR__ . '/models/EDSR_x4.json'), true, 512, JSON_THROW_ON_ERROR);
    if (!hash_equals($manifest['sha256'], hash_file('sha256', $model)))
        throw new RuntimeException('EDSR model checksum mismatch.');
    if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0700, true))
        throw new RuntimeException('Cannot create backup directory.');
    $backupDirectory = realpath($backupDirectory);
    // Backups can contain database passwords; they must stay outside the project/web root.
    if (!$backupDirectory || str_starts_with(strtolower($backupDirectory . DIRECTORY_SEPARATOR), strtolower($project . DIRECTORY_SEPARATOR)))
        throw new RuntimeException('Choose a configuration backup directory outside the project.');
    $file = $project . '/config.local.php';
    $settings = is_file($file) ? require $file : [];
    if (!is_array($settings)) throw new RuntimeException('config.local.php must return an array.');
    if (is_file($file) && !copy($file, $backupDirectory . '/config.local-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.php'))
        throw new RuntimeException('Cannot back up existing configuration.');
    $settings['enhancement_python'] = $python;
    $settings['enhancement_x4_model'] = $model;
    $temp = $project . '/.config-local-' . bin2hex(random_bytes(12)) . '.php';
    if (file_put_contents($temp, "<?php\nreturn " . var_export($settings, true) . ";\n", LOCK_EX) === false)
        throw new RuntimeException('Cannot write configuration.');
    @chmod($temp, 0600);
    if (!rename($temp, $file)) throw new RuntimeException('Cannot replace configuration; original retained.');
    echo "Configuration saved; existing settings preserved.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    $exitCode = 1;
} finally {
    if ($temp && is_file($temp)) unlink($temp);
}
exit($exitCode);
