# Offline high-resolution image enhancement

## Engines

| Choice | Model | Resolution | Output |
| --- | --- | --- | --- |
| **High Resolution ×4 — EDSR** | Deeper pretrained EDSR network, optional 38.6 MB weights | 4× width and height, 16× pixels | Lossless PNG, no output-size target/downsampling |
| AI ×2 (fast) | Bundled 39 KB FSRCNN + whiteware finish | 2× width and height | PNG |
| Whiteware Local | PHP/GD local contrast and sharpening, not AI | Original dimensions | PNG |

EDSR is real learned super-resolution, not bicubic enlargement or a sharper copy from GD. Its result is not mixed back with a bicubic image or passed through the older darkening/unsharp finish. Clean white background pixels and transparency are protected. Both neural modes use overlapping tiles and two CPU threads; there is no GPU, paid API, TensorFlow/PyTorch install or persistent service requirement.

**Higher resolution is not proof of recovered original details.** EDSR may improve edges but cannot guarantee removal of blur or accurate reconstruction of text/fine product features. It is not Real-ESRGAN or a newly trained model. Inspect before approving; do not repeatedly upscale an already upscaled copy.

## Windows / XAMPP: one setup command

After updating the project files, open PowerShell in the project directory:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\tools\image_enhancement\setup_windows.ps1
```

Optional paths:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\tools\image_enhancement\setup_windows.ps1 -Xampp D:\xampp -AiHome D:\lufly-ai
```

The script:
1. Reuses `C:\lufly-ai\venv`, or creates it using Python 3.12 (installs via winget if needed).
2. Installs the existing OpenCV/NumPy/Pillow requirements; do not mix multiple OpenCV distributions in this environment.
3. Downloads the pinned EDSR asset once to `C:\lufly-ai\models\EDSR_x4.pb`. Verifies exact size and SHA-256 before replacing anything. Reruns reuse a valid cached model.
4. Performs a small real ×4 inference smoke test (64×48 → 256×192).
5. Backs up `config.local.php` outside the project to `C:\lufly-ai\backups`, preserves all returned settings and sets the Python and model paths. No manual PHP edits or long pasted PHP/PowerShell blocks.
6. Prints SUCCESS **only** after all steps succeed. It does not change GD, php.ini, Apache settings, the database or product images.

Requires Windows with winget if Python is missing, internet for installation only, PHP GD enabled, PHP `proc_open` allowed, and an Apache account allowed to run/read the configured files. The website is not automatically updated by this script. Keep AiHome outside `htdocs`; backups contain secrets. Configuration backups must not be copied into the public project directory.

Refresh the website and choose **High Resolution ×4 — EDSR**. This becomes the default when Python/model paths exist. Readiness is a path/process capability check, not proof of successful inference; the installer smoke test checks dependencies/model execution.

## Linux / manual configuration

Use Python 3.10+ in a private venv. No downloads happen during enhancement.

```bash
python3 -m venv /opt/lufly-ai/venv
/opt/lufly-ai/venv/bin/python -m pip install -r tools/image_enhancement/requirements.txt
/opt/lufly-ai/venv/bin/python tools/image_enhancement/download_model.py --output /opt/lufly-ai/models/EDSR_x4.pb
/opt/lufly-ai/venv/bin/python tools/image_enhancement/smoke_test.py --model-path /opt/lufly-ai/models/EDSR_x4.pb --output /tmp/lufly-x4-test.png
```

Add to your **existing** `config.local.php` array, preserving other settings:

```php
'enhancement_python' => '/opt/lufly-ai/venv/bin/python',
'enhancement_x4_model' => '/opt/lufly-ai/models/EDSR_x4.pb',
```

Or use `ENHANCEMENT_PYTHON` and `ENHANCEMENT_X4_MODEL` in `.env` (config.local overrides .env). Without an explicit model path the worker/app default is `tools/image_enhancement/models/EDSR_x4.pb`, ignored by Git; outside the web root is preferred.

Full-photo CLI smoke test:

```bash
/opt/lufly-ai/venv/bin/python tools/image_enhancement/enhance.py \
  --profile ai4 --model-path /opt/lufly-ai/models/EDSR_x4.pb \
  --input assets/catalog/5c2bdce34b2c029233e81551.webp --output /tmp/product-x4.png
```

Omitting `--profile` retains the existing FSRCNN ×2 CLI behavior. Python source/output paths must differ. Failed processing retains originals and any existing output; successful outputs are atomically replaced.

## Preview and download

The preview shows engine, source/output pixel dimensions and an optional 100% scrollable detail view. **تنزيل PNG كامل الدقة** downloads the actual full-resolution preview without replacing the product or changing data. Private downloads require the same authenticated session and expire/cancel with the preview. Approval copies the same PNG bytes without JPEG recompression or dimension reduction, replacing only the chosen media record with stale-preview checks. Existing JSON/SQL storage, shared-file safety and cancellation remain active.

## Resource bounds

- Static JPG/PNG/WebP only; GIF and animated WebP rejected. Local sources ≤6 MP and ≤12 MB with a PHP allocation check.
- Neural sources ≤1.5 MP, no silent input downscaling. ×2 output ≤6 MP; ×4 output ≤24 MP. Output file size is not deliberately reduced; the input limits still protect system resources.
- EDSR needs **about 2–3 GB available RAM in addition to PHP**, depending on OpenCV/image size. A CPU catalog photo can take several minutes. The tested 617×348 image took about 132 seconds on the sandbox CPU; other hardware differs.
- EDSR tiles: 128 source pixels, 40-pixel halos to cover the deeper network. Stable nonblocking per-app lock permits only one EDSR request at a time to limit concurrent RAM pressure. Busy requests fail clearly; the lock is released after errors and never unlinked by preview cleanup.
- ×4 worker deadline: 600 seconds; PHP allowance: 620 seconds. ×2 worker deadline: 30 seconds. Apache/proxy/browser infrastructure can impose shorter limits; large batches belong in a separate background workflow.
- Commands use argument arrays and bypass the shell; model paths are trusted administrator configuration, never POST parameters. The worker verifies model SHA-256 every run. Missing/invalid weights, dependencies, dimensions and timeout errors do not fall back to a lesser engine labelled ×4.
- Worker diagnostics go to PHP's error log, not the browser; temporary inputs and failed previews are removed.

## Attribution and verification

Apache-2.0 weights and pinned provenance: `models/SOURCE.md` (FSRCNN), `models/EDSR_SOURCE.md` and `models/EDSR_x4.json` (EDSR). No training was done here.

See `TESTING.md`. The actual catalog image **1685-015** was processed from **617×348 to 2468×1392**, leaving the original unchanged. We reviewed a matching edge crop against bicubic at the same scale; this does not establish universally dramatic quality improvement. Judge the actual PNG before approval. Native Windows/XAMPP/PowerShell execution still needs deployment smoke testing.
