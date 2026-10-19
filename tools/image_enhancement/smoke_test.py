"""Small real EDSR inference test, without any PowerShell inline Python quoting."""
import argparse
import tempfile
from pathlib import Path

from PIL import Image, ImageDraw
from enhance import enhance

if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--model-path', type=Path, required=True)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    with tempfile.TemporaryDirectory() as directory:
        source = Path(directory) / 'input.png'
        image = Image.new('RGB', (64, 48), 'white')
        ImageDraw.Draw(image).ellipse((8, 8, 55, 39), fill=(210, 210, 210), outline=(120, 120, 120))
        image.save(source)
        result = enhance(source, args.output, 'ai4', args.model_path)
        with Image.open(args.output) as output:
            if output.size != (256, 192):
                raise RuntimeError('EDSR smoke test returned incorrect dimensions')
        print(result)
