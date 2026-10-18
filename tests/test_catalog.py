"""Validate the committed catalog snapshot without third-party packages."""
import collections
import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

class CatalogTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.catalog = json.loads((ROOT / 'data/catalog.json').read_text())
        cls.report = json.loads((ROOT / 'data/import-report.json').read_text())

    def test_source_rows_are_not_merged(self):
        products = self.catalog['products']
        self.assertEqual(715, len(products))
        self.assertEqual(len(products), len({(p['source_file'], p['source_row']) for p in products}))
        self.assertEqual(len(products), len({p['id'] for p in products}))
        codes = collections.Counter(p['code'] for p in products if p['code'])
        self.assertGreater(codes['1610-0185'], 1)

    def test_all_drawing_anchors_have_attachments(self):
        media = self.catalog['media']
        self.assertEqual(1465, len(media))
        self.assertEqual(len(media), len({m['id'] for m in media}))
        self.assertEqual(sum(f['anchors'] for f in self.report['files']), len(media))
        self.assertEqual([], self.report['unassigned'])
        self.assertEqual(136, sum(m['source_column'] == 'G' for m in media))

    def test_attachment_files_and_product_links_exist(self):
        products = {p['id']: p for p in self.catalog['products']}
        for m in self.catalog['media']:
            with self.subTest(media=m['id']):
                self.assertIn(m['product_id'], products)
                self.assertEqual(m['source_row'], products[m['product_id']]['source_row'])
                self.assertTrue(m['path'].startswith('assets/catalog/'))
                self.assertNotIn('..', m['path'])
                self.assertTrue((ROOT / m['path']).is_file())
                self.assertGreater((ROOT / m['path']).stat().st_size, 0)

    def test_per_source_attachment_counts(self):
        products = {p['id']: p for p in self.catalog['products']}
        counts = collections.Counter(products[m['product_id']]['source_file'] for m in self.catalog['media'])
        for f in self.report['files']:
            self.assertEqual(f['attachments'], counts[f['file']])

if __name__ == '__main__':
    unittest.main()
