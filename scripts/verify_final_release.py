#!/usr/bin/env python3
"""Verify the standalone handover payload, without installing or executing it."""
from pathlib import Path, PurePosixPath
import hashlib
import json
import sys

ROOT = Path(__file__).resolve().parents[1]
REQUIRED = {
    'artisan', 'composer.json', 'composer.lock', 'package.json', 'package-lock.json',
    'vite.config.js', 'bootstrap/app.php', 'bootstrap/providers.php', 'public/index.php',
    'routes/web.php', 'routes/api.php', 'routes/console.php', 'phpunit.xml',
    'README.md', 'FINAL-HANDOVER.md', 'FINAL-RELEASE.json',
    'docker-compose.production.yml', 'docker/php/Dockerfile',
    'app/Services/Finance/WalletService.php', 'tests/Feature/Finance/WalletWriteRefusalTest.php',
}


def verify(root: Path = ROOT) -> dict:
    manifest = root / 'SOURCE-SHA256SUMS.txt'
    rows = {}
    for line in manifest.read_text(encoding='utf-8').splitlines():
        digest, name = line.split('  ', 1)
        relative = PurePosixPath(name)
        if (len(digest) != 64 or any(c not in '0123456789abcdef' for c in digest)
                or relative.is_absolute() or '..' in relative.parts or str(relative) != name
                or name in rows or '\\' in name):
            raise ValueError('Unsafe, duplicate or invalid source manifest entry')
        rows[name] = digest
    if not REQUIRED <= set(rows):
        raise ValueError('Standalone project entry files are missing')
    for name, digest in rows.items():
        path = root / name
        if path.is_symlink() or not path.is_file():
            raise ValueError('Missing or symlinked source file: ' + name)
        if hashlib.sha256(path.read_bytes()).hexdigest() != digest:
            raise ValueError('Source checksum mismatch: ' + name)
    release = json.loads((root / 'FINAL-RELEASE.json').read_text(encoding='utf-8'))
    if release['payload_files'] != len(rows) or not release['standalone_base_plus_updates']:
        raise ValueError('Release description and manifest disagree')
    # These are generated locally and intentionally not source-manifest entries.
    forbidden = {'.env', '.git/config', '.git/credentials', '.netrc'}
    if forbidden & set(rows) or any(n.startswith(('vendor/', 'node_modules/')) for n in rows):
        raise ValueError('A secret/dependency tree was declared as release source')
    return {'passed': True, 'verified_payload_files': len(rows),
            'manifest_self_hash_is_external': True, 'production_approval': False}


if __name__ == '__main__':
    try:
        print(json.dumps(verify(), indent=2))
    except (OSError, ValueError, KeyError) as error:
        print('Release verification failed: ' + str(error), file=sys.stderr)
        raise SystemExit(1)
