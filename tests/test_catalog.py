"""Validate the committed catalog snapshot without third-party packages.

data/catalog.json is synced from lufly_catalog.sql (the hosted, edited
database dump) by tools/sync_local_from_sql.py — see data/sync-report.json.
It no longer mirrors the original Excel import in data/import-report.json:
hosting edits removed products 22 and 378, verified 224 products, replaced
some images, and added 61 uploaded attachments under uploads/.
"""
import collections
import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

PRODUCT_FIELDS = {'id', 'code', 'brand', 'status', 'description', 'notes',
                  'source_file', 'source_row', 'source_extra', 'archived',
                  'verified', 'version', 'created_at', 'updated_at'}
MEDIA_FIELDS = {'id', 'product_id', 'path', 'original_name', 'kind',
                'caption', 'sort_order', 'previewable', 'source_column',
                'source_row', 'sha256'}


class CatalogTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.catalog = json.loads((ROOT / 'data/catalog.json').read_text())
        cls.report = json.loads((ROOT / 'data/sync-report.json').read_text())

    def test_snapshot_matches_sync_report(self):
        products = self.catalog['products']
        media = self.catalog['media']
        self.assertEqual(713, len(products))
        self.assertEqual(1495, len(media))
        self.assertEqual(self.report['counts']['products'], len(products))
        self.assertEqual(self.report['counts']['media'], len(media))
        self.assertEqual(224, sum(p['verified'] for p in products))
        self.assertEqual([{'name': 'installed', 'value': '1'}],
                         self.catalog['settings'])

    def test_source_rows_are_not_merged(self):
        products = self.catalog['products']
        self.assertEqual(len(products), len({(p['source_file'], p['source_row']) for p in products}))
        self.assertEqual(len(products), len({p['id'] for p in products}))
        codes = collections.Counter(p['code'] for p in products if p['code'])
        self.assertGreater(codes['1610-0185'], 1)

    def test_rows_match_sql_schema(self):
        for product in self.catalog['products']:
            self.assertEqual(PRODUCT_FIELDS, set(product))
        for item in self.catalog['media']:
            self.assertEqual(MEDIA_FIELDS, set(item))
        # معرفات المنتجات المحذوفة على الاستضافة لا يجب أن ترجع في اللوكال
        product_ids = {p['id'] for p in self.catalog['products']}
        self.assertNotIn(22, product_ids)
        self.assertNotIn(378, product_ids)

    def test_attachment_files_and_product_links_exist(self):
        products = {p['id']: p for p in self.catalog['products']}
        media = self.catalog['media']
        self.assertEqual(len(media), len({m['id'] for m in media}))
        documented_missing = set(self.report['missing_files'])
        for m in media:
            with self.subTest(media=m['id']):
                self.assertIn(m['product_id'], products)
                self.assertNotIn('..', m['path'])
                if m['source_column']:
                    # مرفق أصله من Excel (حتى لو استُبدلت صورته على
                    # الاستضافة): صف المصدر يطابق صف منتجه دائمًا
                    self.assertEqual(m['source_row'], products[m['product_id']]['source_row'])
                else:
                    # مرفق أُضيف بالرفع على الاستضافة: بدون صف مصدر
                    self.assertEqual(0, m['source_row'])
                    self.assertTrue(m['path'].startswith('uploads/'))
                if m['path'].startswith('assets/catalog/'):
                    self.assertTrue((ROOT / m['path']).is_file())
                    self.assertGreater((ROOT / m['path']).stat().st_size, 0)
                    # اسم ملف assets هو أول 24 حرفًا من بصمة الصورة الأصلية
                    self.assertEqual(Path(m['path']).stem, m['sha256'][:24])
                else:
                    # ملفات uploads موجودة على الاستضافة؛ أي ملف غير منسوخ
                    # في المستودع بعد يجب أن يكون موثقًا في تقرير المزامنة
                    self.assertTrue(m['path'].startswith('uploads/'))
                    if not (ROOT / m['path']).is_file():
                        self.assertIn(m['path'], documented_missing)

    def test_excel_anchor_counts_after_hosting_edits(self):
        anchored = [m for m in self.catalog['media'] if m['source_column']]
        self.assertEqual(1434, len(anchored))
        self.assertEqual(135, sum(m['source_column'] == 'G' for m in anchored))
        self.assertEqual(61, len(self.catalog['media']) - len(anchored))


if __name__ == '__main__':
    unittest.main()
