"""Private, offline FSRCNN x2 inference worker. No downloads or network calls."""
import argparse
import hashlib
import json
import sys
from pathlib import Path

import cv2
import numpy as np
from PIL import Image, ImageOps

MODEL = Path(__file__).with_name('models') / 'FSRCNN_x2.pb'
MODEL_SHA256 = '366b33f0084c7b3f2bf6724f0a2c77bca94fcec9d7b6d72389d330073b380d5c'
MAX_INPUT_PIXELS = 1_500_000


def whiteware_finish(rgb):
    """Bounded luminance-only detail recovery; white stays white, no recoloring."""
    base = rgb.astype(np.float32)
    denoised = cv2.bilateralFilter(rgb, 5, 12, 2).astype(np.float32)
    base = .75 * base + .25 * denoised
    y = .2126 * base[:, :, 0] + .7152 * base[:, :, 1] + .0722 * base[:, :, 2]
    broad = cv2.GaussianBlur(y, (0, 0), 5)
    fine = cv2.GaussianBlur(y, (0, 0), .85)
    local = np.clip(y - broad, -8, 8)
    detail = y - fine
    detail = np.where(np.abs(detail) > 1.0, np.clip(detail, -5, 5), 0)
    clahe = cv2.createCLAHE(clipLimit=1.4, tileGridSize=(8, 8)).apply(y.astype(np.uint8))
    adaptive = np.clip(clahe.astype(np.float32) - y, -10, 10)
    protect_white = np.clip((253 - y) / 18, 0, 1)
    tone = -10 * np.maximum(0, np.sin(np.pi * np.clip(y / 255, 0, 1))) ** 1.2
    delta = (tone + .65 * local + .9 * detail + .35 * adaptive) * protect_white
    result = np.clip(base + delta[:, :, None], 0, 255)
    # Fully white background must not become gray, and neutral colors remain neutral.
    result[np.all(rgb >= 253, axis=2)] = rgb[np.all(rgb >= 253, axis=2)]
    return np.rint(result).astype(np.uint8)


def upscale_tiled(rgb, model_path=MODEL, tile=192, halo=16):
    if hashlib.sha256(Path(model_path).read_bytes()).hexdigest() != MODEL_SHA256:
        raise ValueError('Model checksum mismatch')
    cv2.setNumThreads(2)
    network = cv2.dnn_superres.DnnSuperResImpl_create()
    network.readModel(str(model_path))
    network.setModel('fsrcnn', 2)
    h, w = rgb.shape[:2]
    output = np.empty((h * 2, w * 2, 3), np.uint8)
    # Overlap/crop avoids seams; no full-image neural activation tensors in memory.
    bgr = cv2.cvtColor(rgb, cv2.COLOR_RGB2BGR)
    for y in range(0, h, tile):
        for x in range(0, w, tile):
            top, left = max(0, y - halo), max(0, x - halo)
            bottom, right = min(h, y + tile + halo), min(w, x + tile + halo)
            patch = network.upsample(bgr[top:bottom, left:right])
            ph, pw = min(tile, h - y), min(tile, w - x)
            output[y * 2:(y + ph) * 2, x * 2:(x + pw) * 2] = patch[(y - top) * 2:(y - top + ph) * 2, (x - left) * 2:(x - left + pw) * 2]
    return cv2.cvtColor(output, cv2.COLOR_BGR2RGB)


def enhance(source, target):
    with Image.open(source) as image:
        if image.width * image.height > MAX_INPUT_PIXELS:
            raise ValueError('AI input exceeds 1.5 megapixels; use the local profile')
        if getattr(image, 'n_frames', 1) != 1:
            raise ValueError('Animated input is not supported')
        rgba = ImageOps.exif_transpose(image).convert('RGBA')
    # Composite invisible RGB onto white before inference to avoid dark alpha fringes.
    background = Image.new('RGB', rgba.size, 'white')
    background.paste(rgba, mask=rgba.getchannel('A'))
    rgb = np.array(background)
    neural = upscale_tiled(rgb)
    bicubic = cv2.resize(rgb, None, fx=2, fy=2, interpolation=cv2.INTER_CUBIC)
    blended = np.rint(.8 * neural.astype(np.float32) + .2 * bicubic).clip(0, 255).astype(np.uint8)
    white = np.all(bicubic >= 253, axis=2)
    blended[white] = bicubic[white]
    result = Image.fromarray(whiteware_finish(blended))
    if rgba.getextrema()[3][0] != 255:
        result.putalpha(rgba.getchannel('A').resize(result.size, Image.Resampling.LANCZOS))
    result.save(target, format='PNG', compress_level=6)
    return {'engine': 'FSRCNN ×2 + Whiteware', 'width': result.width, 'height': result.height, 'scale': 2}


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--input', type=Path, required=True)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    try:
        print(json.dumps(enhance(args.input, args.output)))
    except Exception as error:
        # No traceback or filesystem details in the HTTP response; stderr is for operators.
        print(str(error), file=sys.stderr)
        args.output.unlink(missing_ok=True)
        return 1
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
