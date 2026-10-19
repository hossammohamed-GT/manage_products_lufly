"""Pinned model installer safety, plus optional real EDSR inference tests."""
import hashlib
import importlib.util
import io
import os
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('model_downloader', ROOT / 'tools/image_enhancement/download_model.py')
downloader = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(downloader)


class InstallerTests(unittest.TestCase):
    def test_verified_download_is_atomic_and_existing_valid_model_is_not_downloaded(self):
        payload = b'verified fixture'
        with tempfile.TemporaryDirectory() as folder, patch.dict(downloader.MANIFEST, bytes=len(payload), sha256=hashlib.sha256(payload).hexdigest()):
            target = Path(folder) / 'model.pb'
            downloader.install_model(target, opener=lambda *a, **k: io.BytesIO(payload))
            self.assertEqual(target.read_bytes(), payload)
            downloader.install_model(target, opener=lambda *a, **k: self.fail('Unexpected download'))
            self.assertEqual(list(Path(folder).glob('*.download')), [])

    def test_wrong_hash_truncated_or_oversized_download_preserves_old_file(self):
        for payload in [b'bad', b'', b'oversized']:
            with self.subTest(payload=payload), tempfile.TemporaryDirectory() as folder:
                target = Path(folder) / 'model.pb'
                target.write_bytes(b'old file')
                with patch.dict(downloader.MANIFEST, bytes=3, sha256=hashlib.sha256(b'good').hexdigest()):
                    with self.assertRaises(ValueError):
                        downloader.install_model(target, opener=lambda *a, **k: io.BytesIO(payload))
                self.assertEqual(target.read_bytes(), b'old file')
                self.assertEqual(list(Path(folder).glob('*.download')), [])

    def test_network_failure_does_not_create_a_model(self):
        with tempfile.TemporaryDirectory() as folder:
            def offline(*a, **k):
                raise OSError('network unavailable')
            target = Path(folder) / 'model.pb'
            with self.assertRaises(OSError):
                downloader.install_model(target, opener=offline)
            self.assertFalse(target.exists())
            self.assertEqual(list(Path(folder).iterdir()), [])


MODEL = Path(os.environ.get('LUFLY_TEST_X4_MODEL', '/nonexistent-optional-edsr-model'))
try:
    import cv2
    import numpy as np
    from PIL import Image
    AVAILABLE = MODEL.is_file() and hasattr(cv2, 'dnn_superres')
except ImportError:
    AVAILABLE = False

if AVAILABLE:
    spec = importlib.util.spec_from_file_location('x4_worker', ROOT / 'tools/image_enhancement/enhance.py')
    worker = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(worker)


@unittest.skipUnless(AVAILABLE, 'Set LUFLY_TEST_X4_MODEL and install optional OpenCV packages')
class EdsrTests(unittest.TestCase):
    def fixture(self):
        rgb = np.full((40, 48, 3), 255, np.uint8)
        rgb[8:32, 8:40] = 215
        rgb[16:18, 8:40] = 140
        return rgb

    def test_real_x4_model_not_bicubic_and_no_tile_seams(self):
        rgb = np.pad(self.fixture(), ((0, 0), (0, 48), (0, 0)), constant_values=255)
        rgb[12:28, 60:63] = 150
        whole = worker.upscale_tiled(rgb, MODEL, profile='ai4')
        tiled = worker.upscale_tiled(rgb, MODEL, profile='ai4', tile=32)
        bicubic = cv2.resize(rgb, None, fx=4, fy=4, interpolation=cv2.INTER_CUBIC)
        self.assertEqual(whole.shape, (160, 384, 3))
        self.assertGreater(np.abs(whole.astype(float) - bicubic).mean(), .1)
        self.assertLess(np.abs(whole.astype(float) - tiled).mean(), .1)

    def test_full_pipeline_keeps_alpha_white_original_and_lossless_dimensions(self):
        with tempfile.TemporaryDirectory() as folder:
            source, target = Path(folder) / 'input.png', Path(folder) / 'output.png'
            image = Image.fromarray(self.fixture()).convert('RGBA')
            image.putpixel((0, 0), (0, 0, 0, 0))
            image.save(source)
            original = source.read_bytes()
            meta = worker.enhance(source, target, 'ai4', MODEL)
            self.assertEqual(meta['scale'], 4)
            with Image.open(target) as output:
                self.assertEqual(output.format, 'PNG')
                self.assertEqual(output.size, (192, 160))
                self.assertEqual(output.getpixel((0, 0))[3], 0)
                self.assertEqual(output.getpixel((185, 2))[:3], (255, 255, 255))
            self.assertEqual(source.read_bytes(), original)
            self.assertEqual(list(Path(folder).glob('.enhanced-*')), [])

    def test_missing_or_corrupt_model_never_falls_back_or_damages_existing_output(self):
        with tempfile.TemporaryDirectory() as folder:
            source, target, model = [Path(folder) / n for n in ['input.png', 'output.png', 'bad.pb']]
            Image.fromarray(self.fixture()).save(source)
            target.write_bytes(b'previous output')
            with self.assertRaisesRegex(ValueError, 'missing'):
                worker.enhance(source, target, 'ai4', model)
            model.write_bytes(b'bad weights')
            with self.assertRaisesRegex(ValueError, 'checksum'):
                worker.enhance(source, target, 'ai4', model)
            self.assertEqual(target.read_bytes(), b'previous output')

    def test_in_place_and_oversized_inputs_rejected_without_modifying_source(self):
        with tempfile.TemporaryDirectory() as folder:
            source = Path(folder) / 'input.png'
            Image.new('RGB', (1501, 1000), 'white').save(source)
            original = source.read_bytes()
            with self.assertRaisesRegex(ValueError, 'different'):
                worker.enhance(source, source, 'ai4', MODEL)
            with self.assertRaisesRegex(ValueError, '1.5 megapixels'):
                worker.enhance(source, Path(folder) / 'out.png', 'ai4', MODEL)
            self.assertEqual(source.read_bytes(), original)


if __name__ == '__main__':
    unittest.main()
