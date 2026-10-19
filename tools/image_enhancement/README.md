# Whiteware enhancement and offline neural ×2

## Included engines

- **Whiteware Local (PHP/GD)**: bounded local contrast, shadow separation, noise-thresholded sharpening and highlight protection. Pure white stays white; neutral RGB stays neutral; alpha and dimensions are preserved. This is **not AI** and needs no Python.
- **FSRCNN ×2 + Whiteware (Python/OpenCV)**: real pretrained super-resolution inference, tiled with overlap to limit memory and avoid seams. Followed by restrained denoising, luminance-local contrast/CLAHE and bounded detail enhancement. Alpha is resampled separately, with invisible pixels composited over white during inference.
- **No network access during processing**, paid API, GPU or separate persistent service is needed. The original model (39 KB), Apache-2.0 license and pinned source/checksum are included in `models/`.
- This is an existing pretrained FSRCNN model, not a newly trained model, Real-ESRGAN, or a promise to recover missing product details. Neural inference can alter edges/textures: always retain the before/after approval step.

## Installation: dedicated Python 3.10+ environment

Use a fresh venv; do not mix `opencv-python` and `opencv-contrib-python-headless`. Keep the venv outside the web root. TensorFlow is not needed for inference.

Linux example (replace installation paths):

```bash
python3 -m venv /opt/lufly-image-venv
/opt/lufly-image-venv/bin/python -m pip install -r /path/to/lufly/tools/image_enhancement/requirements.txt
/opt/lufly-image-venv/bin/python /path/to/lufly/tools/image_enhancement/enhance.py \
  --input /path/to/product.webp --output /tmp/lufly-test.png
```

Windows / XAMPP example, from PowerShell in the repository:

```powershell
py -3 -m venv C:\lufly-ai\venv
C:\lufly-ai\venv\Scripts\python.exe -m pip install -r tools\image_enhancement\requirements.txt
C:\lufly-ai\venv\Scripts\python.exe tools\image_enhancement\enhance.py --input assets\catalog\5c2bdce34b2c029233e81551.webp --output C:\lufly-ai\sample.png
```

Add the **absolute executable** to your existing `config.local.php` array, without removing database settings:

```php
'enhancement_python' => 'C:/lufly-ai/venv/Scripts/python.exe',
// Linux: '/opt/lufly-image-venv/bin/python'
```

Alternatively set `ENHANCEMENT_PYTHON` in `.env`. Reload PHP if configuration is cached.

The PHP/Apache user must be allowed to execute that Python and read the worker/model. `proc_open` must not be disabled; temporary storage and `uploads` must be writable. Allow roughly 512 MB of available system RAM for Python, in addition to PHP; actual use depends on image size/OpenCV. Only trusted administrators should configure executables.

## Use

Open a product image and choose:

1. **منتجات بيضاء — تحسين قوي محلي**, or
2. **AI ×2** (default when an interpreter path is configured).

The “configured” label checks the executable path and `proc_open`, not every dependency. Worker failures produce an error, never a hidden local fallback labelled as AI. Use the CLI smoke test above to diagnose dependencies/permissions.

The preview identifies source/output dimensions and the engine. **فحص التفاصيل بالحجم الفعلي (100٪)** shows intrinsic pixels in scrollable panes instead of shrinking the difference away. Approval replaces only the selected media record; shared files and stale-preview protection remain intact.

## Bounds

- Static JPG/PNG/WebP only; GIF and animated WebP remain rejected.
- Local: source ≤6 MP and ≤12 MB, with an estimated PHP-memory check; no enlargement.
- Neural: source ≤1.5 MP, giving ≤6 MP output at twice each dimension. No silent input downscaling.
- Fixed worker command, no shell interpolation, two OpenCV threads, 30-second timeout. PHP's time allowance becomes 45 seconds during enhancement only. Hosting/proxy deadlines may still be lower.
- Missing packages, worker failure and invalid output produce errors without replacing database records. Worker inputs are removed on success/failure.
- Existing CSRF, private previews, cancellation, expiry, session isolation, snapshot, rollback and shared-file handling remain active.

## Verification

See `TESTING.md` for PHP/WASM bridge and Python tests. The real catalog image for **1685-015** was processed from **617×348 to 1234×696**, leaving the original unchanged. That verifies execution and dimensions, not universal visual quality: judge each preview before replacement.
