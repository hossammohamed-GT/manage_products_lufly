"""One-time, explicit model installation. Enhancement itself never downloads files."""
import argparse
import hashlib
import json
import os
import tempfile
import urllib.request
from pathlib import Path

MANIFEST = json.loads((Path(__file__).with_name('models') / 'EDSR_x4.json').read_text(encoding='utf-8'))


def valid_model(path):
    return (path.is_file() and path.stat().st_size == MANIFEST['bytes']
            and hashlib.sha256(path.read_bytes()).hexdigest() == MANIFEST['sha256'])


def install_model(target, opener=urllib.request.urlopen):
    target = Path(target)
    if valid_model(target):
        return target
    target.parent.mkdir(parents=True, exist_ok=True)
    temporary = None
    try:
        request = urllib.request.Request(MANIFEST['url'], headers={'User-Agent': 'LUFLY-model-installer'})
        with opener(request, timeout=60) as response:
            with tempfile.NamedTemporaryFile(dir=target.parent, prefix='.edsr-', suffix='.download', delete=False) as file:
                temporary = Path(file.name)
                digest, total = hashlib.sha256(), 0
                while chunk := response.read(1024 * 1024):
                    total += len(chunk)
                    if total > MANIFEST['bytes']:
                        raise ValueError('Model download is larger than the pinned asset')
                    digest.update(chunk)
                    file.write(chunk)
                file.flush()
                os.fsync(file.fileno())
        if total != MANIFEST['bytes'] or digest.hexdigest() != MANIFEST['sha256']:
            raise ValueError('Model download size/checksum mismatch; existing file retained')
        os.replace(temporary, target)
        return target
    finally:
        if temporary is not None:
            temporary.unlink(missing_ok=True)


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    print(install_model(args.output))
