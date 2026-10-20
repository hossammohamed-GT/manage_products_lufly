<?php
/**
 * Script لاستيراد بيانات المنتجات من catalog.json إلى MySQL
 * 
 * تشغيل: php import_data.php
 * أو من المتصفح: http://your-domain/manage_products_lufly/import_data.php
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);
// تحميل الإعدادات
require_once __DIR__ . '/app.php';
echo "╔══════════════════════════════════════════╗\n";
echo "║   استيراد بيانات المنتجات إلى Database  ║\n";
echo "╚══════════════════════════════════════════╝\n\n";
try {
  // قراءة ملف البيانات
  echo "📖 جاري قراءة ملف catalog.json...\n";
  $json = file_get_contents(__DIR__ . '/data/catalog.json');
  $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

  $productCount = count($data['products']);
  $mediaCount = count($data['media']);

  echo "✅ تم قراءة البيانات:\n";
  echo "   - المنتجات: {$productCount}\n";
  echo "   - الصور: {$mediaCount}\n\n";

  // الاتصال بالـ database
  echo "🔌 جاري الاتصال بـ MySQL...\n";
  $db = db(); // Normal connection with database selected

  // التحقق من وجود المنتجات مسبقاً
  $existingCount = (int) $db->query('SELECT COUNT(*) FROM products')->fetchColumn();

  if ($existingCount > 0) {
    echo "⚠️  تحذير: يوجد بالفعل {$existingCount} منتج في الـ database!\n";
    echo "\n";
    echo "هل تريد:\n";
    echo "1. حذف كل المنتجات القديمة واستيراد البيانات الجديدة؟\n";
    echo "2. إلغاء العملية؟\n";
    echo "\n";

    // لو script من المتصفح: نفذ اختيار المستخدم أو اعرض الأزرار
    if (php_sapi_name() !== 'cli') {
      if (isset($_POST['action'])) {
        checkCsrf();
        if ($_POST['action'] !== 'delete') {
          echo '<p>❌ تم إلغاء العملية.</p>';
          exit;
        }
        echo "\n🗑️  جاري حذف البيانات القديمة...\n";
        $db->exec('DELETE FROM media');
        $db->exec('DELETE FROM products');
        $db->exec('DELETE FROM settings WHERE name = "installed"');
        echo "✅ تم حذف البيانات القديمة.\n\n";
      } else {
        echo '<form method="POST">';
        echo token();
        echo '<button type="submit" name="action" value="delete" class="btn btn-danger">حذف واستيراد</button>';
        echo '<button type="submit" name="action" value="cancel" class="btn btn-secondary">إلغاء</button>';
        echo '</form>';
        echo '<style>
                      body { font-family: Arial, sans-serif; direction: rtl; padding: 20px; background: #f5f5f5; }
                      .btn { padding: 10px 20px; margin: 5px; border: none; border-radius: 5px; cursor: pointer; font-size: 16px; }
                      .btn-danger { background: #e74c3c; color: white; }
                      .btn-danger:hover { background: #c0392b; }
                      .btn-secondary { background: #95a5a6; color: white; }
                      .btn-secondary:hover { background: #7f8c8d; }
                    </style>';
        exit;
      }
    } else {
      // لو من command line، نسأل
      $handle = fopen("php://stdin", "r");
      $line = fgets($handle);
      fclose($handle);

      if (trim((string) $line) !== '1') {
        echo "❌ تم إلغاء العملية.\n";
        exit(0);
      }

      // حذف البيانات القديمة
      echo "\n🗑️  جاري حذف البيانات القديمة...\n";
      $db->exec('DELETE FROM media');
      $db->exec('DELETE FROM products');
      $db->exec('DELETE FROM settings WHERE name = "installed"');
      echo "✅ تم حذف البيانات القديمة.\n\n";
    }
  }

  // استيراد البيانات
  echo "🚀 جاري استيراد البيانات...\n";
  $startTime = microtime(true);

  $db->beginTransaction();

  // استيراد المنتجات
  echo "   📦 استيراد {$productCount} منتج...\n";
  $insertProduct = $db->prepare('
        INSERT INTO products (id, code, brand, status, description, notes, source_file, source_row, source_extra, archived, verified, version, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
  // نحافظ على تعديلات الاستضافة الموجودة في catalog.json (archived/verified/version/التواريخ)
  $now = date('c');

  foreach ($data['products'] as $index => $product) {
    $insertProduct->execute([
      $product['id'],
      $product['code'],
      $product['brand'],
      $product['status'],
      $product['description'],
      $product['notes'],
      $product['source_file'],
      $product['source_row'],
      $product['source_extra'],
      (int) ($product['archived'] ?? 0),
      (int) ($product['verified'] ?? 0),
      max(1, (int) ($product['version'] ?? 1)),
      $product['created_at'] ?? $now,
      $product['updated_at'] ?? $now
    ]);

    if (($index + 1) % 100 === 0) {
      echo "   ✓ تم استيراد " . ($index + 1) . " منتج\n";
    }
  }

  // استيراد الصور
  echo "   🖼️  استيراد {$mediaCount} صورة...\n";
  $insertMedia = $db->prepare('
        INSERT INTO media (id, product_id, path, original_name, kind, caption, sort_order, previewable, source_column, source_row, sha256)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');

  foreach ($data['media'] as $index => $media) {
    $insertMedia->execute([
      $media['id'],
      $media['product_id'],
      $media['path'],
      $media['original_name'],
      $media['kind'],
      $media['caption'],
      $media['sort_order'],
      (int) $media['previewable'],
      $media['source_column'],
      $media['source_row'],
      $media['sha256']
    ]);

    if (($index + 1) % 200 === 0) {
      echo "   ✓ تم استيراد " . ($index + 1) . " صورة\n";
    }
  }

  // حفظ حالة التثبيت
  $db->prepare('INSERT INTO settings (name, value) VALUES ("installed", "1")')->execute();

  $db->commit();

  // محاذاة عدادات الـ id مع قاعدة الاستضافة لو تقرير المزامنة موجود
  try {
    $syncReportPath = __DIR__ . '/data/sync-report.json';
    if (is_file($syncReportPath)) {
      $nextIds = json_decode(file_get_contents($syncReportPath), true)['next_ids'] ?? [];
      foreach (['products', 'media'] as $table) {
        $next = (int) ($nextIds[$table] ?? 0);
        if ($next <= 0) continue;
        if (($config['driver'] ?? '') === 'mysql') $db->exec("ALTER TABLE {$table} AUTO_INCREMENT = {$next}");
        elseif (($config['driver'] ?? '') === 'sqlite') $db->exec("UPDATE sqlite_sequence SET seq = " . ($next - 1) . " WHERE name = '{$table}'");
      }
    }
  } catch (Throwable $e) {
    // المحاذاة اختيارية — الاستيراد نفسه اكتمل
  }

  $duration = round(microtime(true) - $startTime, 2);

  echo "\n";
  echo "╔══════════════════════════════════════════╗\n";
  echo "║         ✅ تم الاستيراد بنجاح!          ║\n";
  echo "╠══════════════════════════════════════════╣\n";
  echo "║  المنتجات: {$productCount}                      ║\n";
  echo "║  الصور: {$mediaCount}                     ║\n";
  echo "║  الوقت: {$duration} ثانية                 ║\n";
  echo "╚══════════════════════════════════════════╝\n";

  if (php_sapi_name() !== 'cli') {
    echo '<br><p><a href="index.php">→ الذهاب للموقع</a></p>';
    echo '<style>body { font-family: Arial, sans-serif; direction: rtl; padding: 20px; }</style>';
  }

} catch (Exception $e) {
  echo "\n❌ خطأ: " . $e->getMessage() . "\n";
  echo "Stack trace:\n" . $e->getTraceAsString() . "\n";

  if (isset($db) && $db->inTransaction()) {
    $db->rollBack();
  }

  exit(1);
}