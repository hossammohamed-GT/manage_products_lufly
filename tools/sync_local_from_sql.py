#!/usr/bin/env python3
"""مزامنة بيانات اللوكال من lufly_catalog.sql (مصدر الحقيقة).

يقرأ ملف phpMyAdmin dump الموجود في جذر المستودع (lufly_catalog.sql) — وهو
النسخة المعدّلة والمؤكدة على الاستضافة — ويحدّث كل ملفات البيانات المحلية:

  - data/catalog.json                  (بذرة التثبيت الأول MySQL/SQLite/JSON)
  - data/local/catalog.json.php        (نسخة عمل وضع الملفات المحلي)
  - data/local/catalog.json.php.backup.php  (نفس اللقطة كنسخة احتياطية سليمة)
  - data/sync-report.json              (تقرير المزامنة: أعداد، ملفات صور
                                        مفقودة، وفروقات عن اللوكال السابق)

الاستخدام:
  python3 tools/sync_local_from_sql.py           # مزامنة فعلية
  python3 tools/sync_local_from_sql.py --check   # تحليل وتقرير فقط بدون كتابة

ملاحظات مهمة:
  - لا توجد مكتبات خارجية؛ المحلّل يفهم هروب MySQL النصي (\\' \\n \\\\ ...)
    ولا يحتاج MySQL مثبتًا.
  - الأداة لا تنسخ ولا تحذف ملفات صور. أي مسار صورة في الـ SQL غير موجود
    على القرص (خصوصًا uploads/* المرفوعة على الاستضافة) يُسجَّل في
    data/sync-report.json تحت missing_files ليتم نسخه يدويًا من الاستضافة.
  - next_ids في لقطة اللوكال تأتي من AUTO_INCREMENT في الـ dump حتى لا
    تُعاد استخدام معرفات منتجات/مرفقات محذوفة.
"""
import collections
import hashlib
import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SQL_PATH = ROOT / 'lufly_catalog.sql'
CATALOG_JSON = ROOT / 'data/catalog.json'
LOCAL_PHP = ROOT / 'data/local/catalog.json.php'
LOCAL_BACKUP = ROOT / 'data/local/catalog.json.php.backup.php'
SYNC_REPORT = ROOT / 'data/sync-report.json'

# نفس ترتيب وأنواع الأعمدة في database/schema.sql و catalogColumns() في
# includes/catalog_storage.php — أى اختلاف هنا يعني dump غير متوقع.
COLUMNS = {
    'products': ['id', 'code', 'brand', 'status', 'description', 'notes',
                 'source_file', 'source_row', 'source_extra', 'archived',
                 'verified', 'version', 'created_at', 'updated_at'],
    'media': ['id', 'product_id', 'path', 'original_name', 'kind', 'caption',
              'sort_order', 'previewable', 'source_column', 'source_row',
              'sha256'],
    'settings': ['name', 'value'],
}
INT_COLUMNS = {'id', 'product_id', 'source_row', 'archived', 'verified',
               'version', 'sort_order', 'previewable'}

LOCAL_HEADER = "<?php http_response_code(404); exit; __halt_compiler();\n"

MYSQL_ESCAPES = {'0': '\0', "'": "'", '"': '"', 'b': '\b', 'n': '\n',
                 'r': '\r', 't': '\t', 'Z': '\x1a', '\\': '\\',
                 '%': '%', '_': '_'}


def unescape_mysql(raw: str) -> str:
    out = []
    i = 0
    while i < len(raw):
        ch = raw[i]
        if ch == '\\' and i + 1 < len(raw):
            out.append(MYSQL_ESCAPES.get(raw[i + 1], raw[i + 1]))
            i += 2
        else:
            out.append(ch)
            i += 1
    return ''.join(out)


def parse_tuples(sql: str, start: int):
    """Parse (v,v,...),(...),... starting after VALUES until ';'."""
    rows = []
    i = start
    n = len(sql)
    while i < n:
        while i < n and sql[i] in ' \t\r\n':
            i += 1
        if i >= n or sql[i] == ';':
            return rows, i
        if sql[i] == ',':
            i += 1
            continue
        if sql[i] != '(':
            raise ValueError(f'Unexpected character {sql[i]!r} at offset {i}')
        i += 1
        row = []
        while True:
            while i < n and sql[i] in ' \t\r\n':
                i += 1
            if sql[i] == "'":
                i += 1
                buf = []
                while i < n:
                    if sql[i] == '\\':
                        if i + 1 < n:
                            buf.append(MYSQL_ESCAPES.get(sql[i + 1], sql[i + 1]))
                            i += 2
                        else:
                            i += 1
                    elif sql[i] == "'":
                        if i + 1 < n and sql[i + 1] == "'":
                            buf.append("'")
                            i += 2
                        else:
                            i += 1
                            break
                    else:
                        buf.append(sql[i])
                        i += 1
                row.append(''.join(buf))
            elif sql.startswith('NULL', i):
                row.append(None)
                i += 4
            else:
                m = re.match(r'-?\d+(?:\.\d+)?', sql[i:])
                if not m:
                    raise ValueError(f'Unparseable value at offset {i}: {sql[i:i+40]!r}')
                token = m.group(0)
                row.append(float(token) if '.' in token else int(token))
                i += len(token)
            while i < n and sql[i] in ' \t\r\n':
                i += 1
            if sql[i] == ',':
                i += 1
                continue
            if sql[i] == ')':
                i += 1
                break
            raise ValueError(f'Unexpected character {sql[i]!r} at offset {i}')
        rows.append(row)
    return rows, i


def parse_dump(sql: str):
    data = {table: [] for table in COLUMNS}
    for match in re.finditer(r'INSERT INTO `(\w+)` \(([^)]*)\) VALUES', sql):
        table = match.group(1)
        if table not in COLUMNS:
            raise ValueError(f'Unexpected table in dump: {table}')
        columns = [c.strip().strip('`') for c in match.group(2).split(',')]
        if columns != COLUMNS[table]:
            raise ValueError(f'Column mismatch for {table}: {columns} != {COLUMNS[table]}')
        rows, _ = parse_tuples(sql, match.end())
        for values in rows:
            if len(values) != len(columns):
                raise ValueError(f'{table}: expected {len(columns)} values, got {len(values)}')
            record = {}
            for column, value in zip(columns, values):
                if value is None:
                    raise ValueError(f'{table}.{column} is NULL but schema is NOT NULL')
                if column in INT_COLUMNS:
                    value = int(value)
                elif not isinstance(value, str):
                    value = str(value)
                record[column] = value
            data[table].append(record)
    for table in ('products', 'media'):
        data[table].sort(key=lambda r: r['id'])
    # AUTO_INCREMENT القادم لكل جدول — مصدر next_ids
    next_ids = {}
    for table in ('products', 'media'):
        m = re.search(rf'ALTER TABLE `{table}`\s+MODIFY[^;]*AUTO_INCREMENT=(\d+)', sql, re.S)
        if not m:
            raise ValueError(f'AUTO_INCREMENT not found for {table}')
        next_ids[table] = int(m.group(1))
    generation = re.search(r'-- Generation Time:\s*(.+)', sql)
    return data, next_ids, (generation.group(1).strip() if generation else '')


def validate(data, next_ids):
    """نفس قواعد JsonCatalogStore::validate() في includes/catalog_storage.php"""
    ids = {}
    for table in ('products', 'media'):
        seen = set()
        for row in data[table]:
            if set(row.keys()) != set(COLUMNS[table]):
                raise ValueError(f'{table}#{row.get("id")}: unexpected fields {sorted(row.keys())}')
            if not isinstance(row['id'], int) or row['id'] <= 0 or row['id'] in seen:
                raise ValueError(f'{table}: invalid/duplicate id {row["id"]!r}')
            seen.add(row['id'])
            for column in COLUMNS[table]:
                value = row[column]
                if column in INT_COLUMNS and not isinstance(value, int):
                    raise ValueError(f'{table}.{column} must be int')
                if column not in INT_COLUMNS and not isinstance(value, str):
                    raise ValueError(f'{table}.{column} must be string')
        if next_ids[table] <= max(seen, default=0):
            raise ValueError(f'{table}: next id {next_ids[table]} not greater than max id')
        ids[table] = seen
    for row in data['media']:
        if row['product_id'] not in ids['products']:
            raise ValueError(f'media#{row["id"]}: orphan, product {row["product_id"]} missing')
    names = [row['name'] for row in data['settings']]
    if len(names) != len(set(names)):
        raise ValueError('settings: duplicate names')


def group_of(code: str) -> str:
    code = code.strip()
    pos = code.find('-')
    return code[:pos].strip().upper() if pos > 0 else 'غير مصنف'


def read_previous_local():
    """يقرأ لقطة اللوكال السابقة للمقارنة — يعيد None لو غير موجودة/تالفة."""
    for path, guarded in ((LOCAL_PHP, True), (CATALOG_JSON, False)):
        try:
            text = path.read_text(encoding='utf-8')
            if guarded:
                if not text.startswith(LOCAL_HEADER):
                    continue
                text = text[len(LOCAL_HEADER):]
            parsed = json.loads(text)
            if isinstance(parsed.get('products'), list) and isinstance(parsed.get('media'), list):
                return parsed
        except Exception:
            continue
    return None


def diff_report(previous, current):
    if previous is None:
        return None
    report = {}
    content_fields = {
        'products': ['code', 'brand', 'status', 'description', 'notes',
                     'source_file', 'source_row', 'source_extra',
                     'archived', 'verified'],
        'media': COLUMNS['media'],
    }
    for table in ('products', 'media'):
        old = {row['id']: row for row in previous[table]}
        new = {row['id']: row for row in current[table]}
        added = sorted(set(new) - set(old))
        deleted = sorted(set(old) - set(new))
        field_changes = collections.Counter()
        modified = []
        for row_id in sorted(set(old) & set(new)):
            changed = False
            for field in content_fields[table]:
                if field in old[row_id] and old[row_id][field] != new[row_id][field]:
                    # previewable كان boolean في الكتالوج القديم
                    if field == 'previewable' and int(old[row_id][field]) == new[row_id][field]:
                        continue
                    field_changes[field] += 1
                    changed = True
            if changed:
                modified.append(row_id)
        report[table] = {
            'added_ids': added,
            'deleted_ids': deleted,
            'modified_ids': modified,
            'modified_count': len(modified),
            'field_changes': dict(sorted(field_changes.items())),
        }
    return report


def file_report(data):
    referenced = [row['path'] for row in data['media']]
    unique = sorted(set(referenced))
    missing, sha_mismatch = [], []
    for path in unique:
        target = ROOT / path
        if not target.is_file():
            missing.append(path)
    assets_dir = ROOT / 'assets/catalog'
    on_disk = set()
    if assets_dir.is_dir():
        on_disk = {f'assets/catalog/{p.name}' for p in assets_dir.iterdir() if p.is_file()}
    orphans = sorted(on_disk - set(unique))
    return {
        'referenced_files': len(referenced),
        'unique_files': len(unique),
        'missing_files': missing,
        'missing_uploads': [p for p in missing if p.startswith('uploads/')],
        'missing_assets': [p for p in missing if p.startswith('assets/')],
        'orphan_asset_files': orphans,
    }


def sha_report(data):
    """يتحقق من سلامة sha256 حسب مصدر الملف.

    ملفات assets/catalog/*: الـ sha256 المخزن هو بصمة الصورة الأصلية قبل
    تحويلها إلى WebP، واسم الملف نفسه أول 24 حرفًا منها — لذلك التحقق
    الصحيح هو مطابقة اسم الملف، وليس بصمة محتوى الـ WebP الحالي.
    ملفات uploads/*: تُحفظ كما هي عند الرفع، وبصمتها هي بصمة الملف نفسه،
    فتُفحص فعليًا عندما يكون الملف موجودًا على القرص.
    """
    mismatches = []
    hashes = {}
    for row in data['media']:
        path = row['path']
        if not row['sha256']:
            continue
        if path.startswith('assets/catalog/'):
            if Path(path).stem != row['sha256'][:24]:
                mismatches.append({'media_id': row['id'], 'path': path,
                                   'reason': 'asset filename does not match stored sha256 prefix'})
        else:
            if path not in hashes:
                target = ROOT / path
                hashes[path] = (hashlib.sha256(target.read_bytes()).hexdigest()
                                if target.is_file() else None)
            actual = hashes[path]
            if actual is not None and actual != row['sha256']:
                mismatches.append({'media_id': row['id'], 'path': path,
                                   'reason': 'uploaded file content hash differs from stored sha256'})
    return mismatches


def main():
    check_only = '--check' in sys.argv
    sql = SQL_PATH.read_text(encoding='utf-8')
    data, next_ids, generation = parse_dump(sql)
    validate(data, next_ids)

    previous = read_previous_local()
    changes = diff_report(previous, data)
    files = file_report(data)
    mismatches = sha_report(data)

    products = data['products']
    report = {
        'source': 'lufly_catalog.sql',
        'source_description': 'phpMyAdmin dump من قاعدة بيانات الاستضافة — الداتا المعدّلة والمؤكدة، ومصدر الحقيقة الحالي للوكال',
        'dump_generation_time': generation,
        'synced_at': '2026-10-20',
        'sync_tool': 'tools/sync_local_from_sql.py',
        'counts': {
            'products': len(products),
            'media': len(data['media']),
            'settings': len(data['settings']),
            'verified_products': sum(p['verified'] for p in products),
            'archived_products': sum(p['archived'] for p in products),
            'groups': len({group_of(p['code']) for p in products}),
            'unique_files': files['unique_files'],
        },
        'next_ids': next_ids,
        'missing_files': files['missing_files'],
        'orphan_asset_files': files['orphan_asset_files'],
        'sha256_mismatches': mismatches,
        'changes_vs_previous_local': changes,
        'notes': [
            'missing_files: ملفات صور مذكورة في الـ SQL لكنها غير موجودة في المستودع — انسخ مجلد uploads من الاستضافة بجانب بيانات اللوكال.',
            'orphan_asset_files: ملفات في assets/catalog لم تعد مرتبطة بأي مرفق في الـ SQL — تُركت كما هي ولم تُحذف.',
            'حقل anchor_type كان موجودًا في كتالوج Excel القديم ولا يوجد في سكيما SQL، لذلك لم يعد في بيانات اللوكال.',
            'sha256 لملفات assets/catalog هو بصمة الصورة الأصلية قبل تحويل WebP (اسم الملف أول 24 حرفًا منها)، ولمطابقة المحتوى فعليًا تُفحص ملفات uploads فقط.',
        ],
    }

    print(f"المصدر: lufly_catalog.sql ({generation})")
    print(f"منتجات: {report['counts']['products']} | مرفقات: {report['counts']['media']} | "
          f"مؤكدة: {report['counts']['verified_products']} | مؤرشفة: {report['counts']['archived_products']} | "
          f"مجموعات: {report['counts']['groups']}")
    print(f"next_ids: {next_ids}")
    if changes:
        for table in ('products', 'media'):
            c = changes[table]
            print(f"{table}: مضاف {len(c['added_ids'])}، محذوف {len(c['deleted_ids'])}، "
                  f"معدّل {c['modified_count']} — حقول: {c['field_changes']}")
    print(f"ملفات صور مفقودة من المستودع: {len(files['missing_files'])} "
          f"(uploads: {len(files['missing_uploads'])}، assets: {len(files['missing_assets'])})")
    print(f"ملفات assets يتيمة (لم تُحذف): {len(files['orphan_asset_files'])}")
    print(f"عدم تطابق sha256: {len(mismatches)}")

    if check_only:
        print('--check: لم تتم كتابة أي ملفات.')
        return

    catalog = {'version': 1, 'products': data['products'],
               'media': data['media'], 'settings': data['settings']}
    CATALOG_JSON.write_text(json.dumps(catalog, ensure_ascii=False, indent=2) + '\n',
                            encoding='utf-8')

    snapshot = {'schema_version': 1, 'products': data['products'],
                'media': data['media'], 'settings': data['settings'],
                'next_ids': next_ids}
    payload = LOCAL_HEADER + json.dumps(snapshot, ensure_ascii=False,
                                        separators=(',', ':'))
    LOCAL_PHP.parent.mkdir(parents=True, exist_ok=True)
    LOCAL_PHP.write_text(payload, encoding='utf-8')
    LOCAL_BACKUP.write_text(payload, encoding='utf-8')

    SYNC_REPORT.write_text(json.dumps(report, ensure_ascii=False, indent=2) + '\n',
                           encoding='utf-8')
    print('تم تحديث: data/catalog.json، data/local/catalog.json.php (+backup)، data/sync-report.json')


if __name__ == '__main__':
    main()
