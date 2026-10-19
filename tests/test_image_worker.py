"""Run with the optional AI venv: python -m unittest discover -s tests."""
import importlib.util
import tempfile
import unittest
import warnings
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
try:
    import cv2
    import numpy as np
    from PIL import Image
    AVAILABLE = hasattr(cv2, 'dnn_superres')
except ImportError:
    AVAILABLE = False

if AVAILABLE:
    spec = importlib.util.spec_from_file_location('image_worker', ROOT / 'tools/image_enhancement/enhance.py')
    worker = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(worker)


@unittest.skipUnless(AVAILABLE, 'Install tools/image_enhancement/requirements.txt in a venv for AI tests')
class ImageWorkerTests(unittest.TestCase):
    def fixture(self, width=90, height=70):
        rgb = np.full((height, width, 3), 255, dtype=np.uint8)
        rgb[10:-10, 10:-10] = 222
        rgb[20:23, 12:-12] = 175
        return rgb

    def test_white_and_neutral_colors_are_preserved_without_numeric_warnings(self):
        with warnings.catch_warnings():
            warnings.simplefilter('error', RuntimeWarning)
            result = worker.whiteware_finish(self.fixture())
        np.testing.assert_array_equal(result[:4], self.fixture()[:4])
        np.testing.assert_array_equal(result[:, :, 0], result[:, :, 1])
        np.testing.assert_array_equal(result[:, :, 1], result[:, :, 2])
        self.assertGreater(np.abs(result.astype(float) - self.fixture()).mean(), .5)
        self.assertLess(np.abs(result.astype(float) - self.fixture()).max(), 30)

    def test_real_model_upscales_and_is_not_just_bicubic_interpolation(self):
        rgb = self.fixture()
        neural = worker.upscale_tiled(rgb)
        bicubic = cv2.resize(rgb, None, fx=2, fy=2, interpolation=cv2.INTER_CUBIC)
        self.assertEqual(neural.shape, (140, 180, 3))
        self.assertGreater(np.abs(neural.astype(float) - bicubic).mean(), .1)

    def test_overlapped_tiles_do_not_introduce_seams(self):
        rgb = self.fixture()
        whole = worker.upscale_tiled(rgb, tile=192)
        tiled = worker.upscale_tiled(rgb, tile=32)
        self.assertLess(np.abs(whole.astype(float) - tiled).mean(), .1)

    def test_png_alpha_and_dimensions_survive_full_worker(self):
        with tempfile.TemporaryDirectory() as directory:
            source, target = Path(directory) / 'input.png', Path(directory) / 'output.png'
            rgba = Image.fromarray(self.fixture()).convert('RGBA')
            rgba.putpixel((0, 0), (0, 0, 0, 0))
            rgba.save(source)
            metadata = worker.enhance(source, target)
            with Image.open(target) as result:
                self.assertEqual(result.size, (180, 140))
                self.assertEqual(result.getpixel((0, 0))[3], 0)
            self.assertEqual(metadata['scale'], 2)
            with Image.open(source) as original:
                self.assertEqual(original.size, (90, 70))

    def test_tampered_model_is_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            model = Path(directory) / 'bad.pb'
            model.write_bytes(b'wrong model')
            with self.assertRaisesRegex(ValueError, 'checksum'):
                worker.upscale_tiled(self.fixture(), model_path=model)

    def test_oversized_input_is_rejected_before_inference(self):
        with tempfile.TemporaryDirectory() as directory:
            source = Path(directory) / 'large.png'
            Image.new('RGB', (1501, 1000), 'white').save(source)
            with self.assertRaisesRegex(ValueError, '1.5 megapixels'):
                worker.enhance(source, Path(directory) / 'output.png')

    def test_real_catalog_sample_preserves_original_and_doubles_dimensions(self):
        source = ROOT / 'assets/catalog/5c2bdce34b2c029233e81551.webp'
        before = source.read_bytes()
        with tempfile.TemporaryDirectory() as directory:
            target = Path(directory) / 'result.png'
            metadata = worker.enhance(source, target)
            self.assertEqual((metadata['width'], metadata['height']), (1234, 696))
            self.assertEqual(source.read_bytes(), before)


if __name__ == '__main__':
    unittest.main()
