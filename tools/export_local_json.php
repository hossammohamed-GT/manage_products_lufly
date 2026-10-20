<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (in_array('--help', $argv, true)) {
    echo "Usage: php tools/export_local_json.php [new-path-ending-in.php]\n";
    exit;
}
$temporary = null;
$exitCode = 0;
try {
    require dirname(__DIR__) . '/app.php';

    $source = require dirname(__DIR__) . '/config.php';
    if (is_file(dirname(__DIR__) . '/config.local.php')) $source = array_replace($source, require dirname(__DIR__) . '/config.local.php');
    $source['storage_mode'] = 'database';
    $config = $source;
    $config['driver'] = catalogDriver($source, $_SERVER, PHP_SAPI);
    $database = db();
    $database->beginTransaction();
    try {
        $seed = ['products'=>catalogRows($database,'products'),'media'=>catalogRows($database,'media'),'settings'=>catalogRows($database,'settings')];
        $database->commit();
    } catch (Throwable $e) {
        if ($database->inTransaction()) $database->rollBack();
        throw $e;
    }
    $temporary = tempnam(sys_get_temp_dir(), 'lufly-export-');
    if ($temporary === false) throw new RuntimeException('تعذر تجهيز التصدير');
    @chmod($temporary, 0600);
    if (file_put_contents($temporary, json_encode($seed, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) === false) throw new RuntimeException('تعذر كتابة التصدير');
    $destination = $argv[1] ?? $config['json_path'];
    $files = new JsonCatalogStore($destination, $temporary, true);
    echo 'تم تصدير ' . count($seed['products']) . ' منتج و' . count($seed['media']) . " مرفق إلى ملف JSON مستقل.\n";
    echo "قاعدة البيانات لم تتغير. اضبط json_path على ملف التصدير وAPP_STORAGE=files لاستخدامه.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'فشل التصدير: ' . $e->getMessage() . "\n");
    $exitCode = 1;
} finally {
    if (is_string($temporary) && is_file($temporary)) @unlink($temporary);
}

exit($exitCode);
