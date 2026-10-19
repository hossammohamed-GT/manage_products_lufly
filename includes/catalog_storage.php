<?php
declare(strict_types=1);


function catalogDriver(array $config, array $server, string $sapi): string
{
    $mode = $config['storage_mode'] ?? 'auto';
    if (!in_array($mode, ['auto','files','database'], true)) throw new RuntimeException('APP_STORAGE يجب أن يكون auto أو files أو database');
    $driver = $config['driver'] ?? 'mysql';
    if (!in_array($driver, ['mysql','sqlite','json'], true)) throw new RuntimeException('نوع التخزين غير صالح');
    if ($mode === 'files' || ($mode === 'auto' && $driver === 'json')) return 'json';
    if ($mode === 'database') {
        if ($driver === 'json') throw new RuntimeException('اختر mysql أو sqlite مع وضع database');
        return $driver;
    }
    $loopback = fn($ip) => in_array($ip, ['127.0.0.1','::1','::ffff:127.0.0.1'], true);
    if ($sapi === 'cli') return 'json';
    $local = false;
    if ($loopback($server['REMOTE_ADDR'] ?? '')) {
        $name = strtolower($server['SERVER_NAME'] ?? '');
        $namedLocal = in_array($name, ['localhost','127.0.0.1','::1','[::1]'], true) || str_ends_with($name, '.localhost');
        $local = ($namedLocal && $loopback($server['SERVER_ADDR'] ?? '')) ||
            ($sapi === 'cli-server' && ($namedLocal || in_array($name, ['0.0.0.0','::'], true)));
    }
    return $local ? 'json' : $driver;
}


function catalogColumns(string $table): array
{
    return match ($table) {
        'products' => ['id','code','brand','status','description','notes','source_file','source_row','source_extra','archived','verified','version','created_at','updated_at'],
        'media' => ['id','product_id','path','original_name','kind','caption','sort_order','previewable','source_column','source_row','sha256'],
        'settings' => ['name','value'],
        default => throw new InvalidArgumentException('Unknown catalog table'),
    };
}
function catalogCheckColumns(string $table, array $columns): void
{
    if (array_diff($columns, catalogColumns($table))) throw new InvalidArgumentException('Unknown catalog field');
}
function catalogWhere(string $table, array $criteria, array &$params): string
{
    catalogCheckColumns($table, array_keys($criteria));
    $parts = [];
    foreach ($criteria as $column => $value) {
        if (is_array($value)) {
            $parts[] = $value ? $column . ' IN (' . implode(',', array_fill(0, count($value), '?')) . ')' : '1=0';
            array_push($params, ...array_values($value));
        } else { $parts[] = $column . '=?'; $params[] = $value; }
    }
    return $parts ? ' WHERE ' . implode(' AND ', $parts) : '';
}
function catalogRows(PDO|JsonCatalogStore $db, string $table, array $criteria = [], array $order = []): array
{
    catalogCheckColumns($table, array_keys($order));
    if ($db instanceof JsonCatalogStore) return $db->select($table, $criteria, $order);
    $params = [];
    $sql = 'SELECT * FROM ' . $table . catalogWhere($table, $criteria, $params);
    if ($order) $sql .= ' ORDER BY ' . implode(',', array_map(fn($key) => $key . ($order[$key] === 'DESC' ? ' DESC' : ' ASC'), array_keys($order)));
    return run($db, $sql, $params)->fetchAll();
}
function catalogRow(PDO|JsonCatalogStore $db, string $table, array $criteria): array|false
{
    return catalogRows($db, $table, $criteria)[0] ?? false;
}
function catalogInsert(PDO|JsonCatalogStore $db, string $table, array $values): int
{
    catalogCheckColumns($table, array_keys($values));
    if (!$values) throw new InvalidArgumentException('Empty catalog insert');
    if ($db instanceof JsonCatalogStore) return $db->insert($table, $values);
    run($db, 'INSERT INTO ' . $table . ' (' . implode(',', array_keys($values)) . ') VALUES (' . implode(',', array_fill(0, count($values), '?')) . ')', array_values($values));
    return (int)$db->lastInsertId();
}
function catalogUpdate(PDO|JsonCatalogStore $db, string $table, array $values, array $criteria, bool $bumpVersion = false): int
{
    catalogCheckColumns($table, array_keys($values));
    if (!$criteria || !$values || ($bumpVersion && $table !== 'products')) throw new InvalidArgumentException('Unsafe catalog update');
    if ($db instanceof JsonCatalogStore) return $db->update($table, $values, $criteria, $bumpVersion);
    $params = array_values($values);
    $assignments = array_map(fn($column) => $column . '=?', array_keys($values));
    if ($bumpVersion) $assignments[] = 'version=version+1';
    return run($db, 'UPDATE ' . $table . ' SET ' . implode(',', $assignments) . catalogWhere($table, $criteria, $params), $params)->rowCount();
}
function catalogDelete(PDO|JsonCatalogStore $db, string $table, array $criteria): int
{
    if (!$criteria) throw new InvalidArgumentException('Unsafe catalog delete');
    if ($db instanceof JsonCatalogStore) return $db->delete($table, $criteria);
    $params = [];
    return run($db, 'DELETE FROM ' . $table . catalogWhere($table, $criteria, $params), $params)->rowCount();
}


final class JsonCatalogStore
{
    public const HEADER = "<?php http_response_code(404); exit; __halt_compiler();\n";
    private array $data;
    private ?array $before = null;
    private $lock = null;

    public function __construct(private string $path, private string $seed, bool $requireNew = false)
    {
        if (!str_ends_with(strtolower($path), '.php') || (realpath($path) !== false && realpath($path) === realpath($seed)))
            throw new RuntimeException('ملف التخزين المحلي يجب أن ينتهي بـ.php لحماية بيانات JSON ولا يجوز أن يكون ملف الاستيراد الأصلي.');
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory))
            throw new RuntimeException('تعذر إنشاء مجلد البيانات المحلية. راجع صلاحيات data/local.');
        $this->acquire(LOCK_EX);
        try {
            if ($requireNew && file_exists($path)) throw new RuntimeException('ملف الوجهة موجود بالفعل. اختر مسارًا جديدًا؛ لن تتم الكتابة فوق بيانات محلية موجودة.');
            if (!file_exists($path)) {
                $seedData = json_decode((string)@file_get_contents($seed), true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($seedData['products'] ?? null) || !is_array($seedData['media'] ?? null)) throw new RuntimeException('بيانات الاستيراد غير صالحة');
                $settings = $seedData['settings'] ?? [['name'=>'installed','value'=>'1']];
                if (!is_array($settings)) throw new RuntimeException('إعدادات الاستيراد غير صالحة');
                $this->data = ['schema_version'=>1, 'products'=>[], 'media'=>[], 'settings'=>$settings, 'next_ids'=>['products'=>1,'media'=>1]];
                foreach (['products','media'] as $table) foreach ($seedData[$table] as $row) {
                    $row = $this->defaults($table, $row);
                    $this->data[$table][] = $row;
                    $this->data['next_ids'][$table] = max($this->data['next_ids'][$table], $row['id'] + 1);
                }
                $this->validate($this->data);
                $this->atomicWrite($path, $this->data);
            } else { $this->data = $this->read(); }
        } finally { $this->release(); }
    }
    private function acquire(int $mode): void
    {
        $this->lock = @fopen($this->path . '.lock', 'c+b');
        if (!$this->lock) throw new RuntimeException('تعذر فتح قفل البيانات المحلية');
        $deadline = microtime(true) + 5;
        do {
            if (@flock($this->lock, $mode | LOCK_NB)) return;
            usleep(20000);
        } while (microtime(true) < $deadline);
        $this->release();
        throw new RuntimeException('البيانات المحلية مشغولة. حاول مرة أخرى.');
    }
    private function release(): void
    {
        if (is_resource($this->lock)) { flock($this->lock, LOCK_UN); fclose($this->lock); }
        $this->lock = null;
    }
    private function read(): array
    {
        $bytes = @file_get_contents($this->path);
        try {
            if ($bytes === false || !str_starts_with($bytes, self::HEADER)) throw new RuntimeException('Invalid snapshot header');
            $data = json_decode(substr($bytes, strlen(self::HEADER)), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($data)) throw new RuntimeException('Invalid snapshot');
            $this->validate($data);
            return $data;
        } catch (Throwable $e) {
            throw new RuntimeException('ملف البيانات المحلية تالف أو غير قابل للقراءة. لم تتم إعادة استيراده أو الكتابة فوقه. راجع النسخة الاحتياطية .backup.php.', 0, $e);
        }
    }
    private function validate(array $data): void
    {
        if (($data['schema_version'] ?? null) !== 1 || !is_array($data['settings'] ?? null)) throw new RuntimeException('Invalid snapshot version');
        $ids = [];
        foreach (['products','media'] as $table) {
            if (!is_array($data[$table] ?? null) || !array_is_list($data[$table])) throw new RuntimeException('Invalid record list');
            $ids[$table] = [];
            foreach ($data[$table] as $row) {
                if (!is_array($row) || !is_int($row['id'] ?? null) || $row['id'] <= 0 || isset($ids[$table][$row['id']])) throw new RuntimeException('Invalid/duplicate record id');
                foreach (catalogColumns($table) as $column) {
                    if (!array_key_exists($column, $row)) throw new RuntimeException('Missing record field');
                    $integer = in_array($column, ['id','product_id','source_row','archived','verified','version','sort_order','previewable'], true);
                    if ($integer ? !is_int($row[$column]) : !is_string($row[$column])) throw new RuntimeException('Invalid field type');
                }
                $ids[$table][$row['id']] = true;
            }
            $next = $data['next_ids'][$table] ?? null;
            if (!is_int($next) || $next <= max(array_keys($ids[$table]) ?: [0])) throw new RuntimeException('Invalid next id');
        }
        foreach ($data['media'] as $row) if (!isset($ids['products'][$row['product_id']])) throw new RuntimeException('Orphan attachment');
        $names = [];
        foreach ($data['settings'] as $row) {
            if (!is_array($row) || !is_string($row['name'] ?? null) || !is_string($row['value'] ?? null) || isset($names[$row['name']])) throw new RuntimeException('Invalid setting');
            $names[$row['name']] = true;
        }
    }
    private function defaults(string $table, array $row): array
    {
        $defaults = $table === 'products'
            ? ['id'=>0,'code'=>'','brand'=>'lufly','status'=>'Running','description'=>'','notes'=>'','source_file'=>'','source_row'=>0,'source_extra'=>'{}','archived'=>0,'verified'=>0,'version'=>1,'created_at'=>date('c'),'updated_at'=>date('c')]
            : ['id'=>0,'product_id'=>0,'path'=>'','original_name'=>'','kind'=>'photo','caption'=>'','sort_order'=>0,'previewable'=>1,'source_column'=>'','source_row'=>0,'sha256'=>''];
        if ($table === 'settings') $defaults = ['name'=>'','value'=>''];
        $result = array_replace($defaults, $row);
        foreach (['id','product_id','source_row','archived','verified','version','sort_order','previewable'] as $key) if (isset($result[$key])) $result[$key] = (int)$result[$key];
        return $result;
    }
    private function atomicWrite(string $path, array $data): void
    {
        $bytes = self::HEADER . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $temporary = dirname($path) . '/.catalog-' . bin2hex(random_bytes(12)) . '.tmp.php';
        $handle = @fopen($temporary, 'xb');
        if (!$handle) throw new RuntimeException('تعذر إنشاء ملف بيانات مؤقت. لم تُحفظ التغييرات.');
        try {
            @chmod($temporary, 0600);
            for ($offset = 0; $offset < strlen($bytes); $offset += $written) {
                $written = @fwrite($handle, substr($bytes, $offset));
                if ($written === false || $written === 0) throw new RuntimeException('فشلت كتابة البيانات المحلية');
            }
            if (!@fflush($handle)) throw new RuntimeException('فشل حفظ البيانات المحلية');
            if (function_exists('fsync') && !@fsync($handle)) throw new RuntimeException('فشل تثبيت البيانات المحلية');
            fclose($handle); $handle = null;
            if (!@rename($temporary, $path)) throw new RuntimeException('تعذر استبدال ملف البيانات. النسخة السابقة لم تتغير.');
        } finally {
            if (is_resource($handle)) fclose($handle);
            if (is_file($temporary)) @unlink($temporary);
        }
    }
    public function beginTransaction(): bool
    {
        if ($this->before !== null) throw new RuntimeException('Nested file transactions are not supported');
        $this->acquire(LOCK_EX);
        try { $this->data = $this->read(); $this->before = $this->data; }
        catch (Throwable $e) { $this->release(); throw $e; }
        return true;
    }
    public function commit(): bool
    {
        if ($this->before === null) throw new RuntimeException('No file transaction');
        $this->validate($this->data);
        $this->atomicWrite($this->path . '.backup.php', $this->before);
        $this->atomicWrite($this->path, $this->data);
        $this->before = null; $this->release();
        return true;
    }
    public function rollBack(): bool
    {
        if ($this->before === null) return false;
        $this->data = $this->before; $this->before = null; $this->release();
        return true;
    }
    public function inTransaction(): bool { return $this->before !== null; }
    private function matches(array $row, array $criteria): bool
    {
        foreach ($criteria as $key => $value) {
            if (is_array($value) ? !in_array($row[$key] ?? null, $value, true) : ($row[$key] ?? null) !== $value) return false;
        }
        return true;
    }
    public function select(string $table, array $criteria, array $order): array
    {
        catalogCheckColumns($table, array_keys($criteria));
        $rows = array_values(array_filter($this->data[$table], fn($row) => $this->matches($row, $criteria)));
        if ($order) usort($rows, function($a, $b) use ($order) {
            foreach ($order as $key => $direction) if (($compare = $a[$key] <=> $b[$key]) !== 0) return $direction === 'DESC' ? -$compare : $compare;
            return 0;
        });
        return $rows;
    }
    private function mutate(callable $operation): mixed
    {
        $ownTransaction = !$this->inTransaction();
        if ($ownTransaction) $this->beginTransaction();
        try { $result = $operation(); if ($ownTransaction) $this->commit(); return $result; }
        catch (Throwable $e) { if ($ownTransaction) $this->rollBack(); throw $e; }
    }
    public function insert(string $table, array $values): int
    {
        return $this->mutate(function() use ($table, $values) {
            if ($table !== 'settings') {
                $values['id'] = (int)($values['id'] ?? $this->data['next_ids'][$table]);
                $this->data['next_ids'][$table] = max($this->data['next_ids'][$table], $values['id'] + 1);
            }
            $this->data[$table][] = $this->defaults($table, $values);
            return (int)($values['id'] ?? 0);
        });
    }
    public function update(string $table, array $values, array $criteria, bool $bumpVersion): int
    {
        catalogCheckColumns($table, array_keys($criteria));
        return $this->mutate(function() use ($table, $values, $criteria, $bumpVersion) {
            $count = 0;
            foreach ($this->data[$table] as &$row) if ($this->matches($row, $criteria)) {
                $row = array_replace($row, $values); if ($bumpVersion) $row['version']++; $count++;
            }
            unset($row); return $count;
        });
    }
    public function delete(string $table, array $criteria): int
    {
        catalogCheckColumns($table, array_keys($criteria));
        return $this->mutate(function() use ($table, $criteria) {
            $before = count($this->data[$table]);
            $this->data[$table] = array_values(array_filter($this->data[$table], fn($row) => !$this->matches($row, $criteria)));
            return $before - count($this->data[$table]);
        });
    }
    public function __destruct() { $this->rollBack(); $this->release(); }
}
