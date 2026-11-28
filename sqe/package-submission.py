"""Package the working repository with its Git history and selected raw evidence."""

import argparse
import hashlib
import subprocess
from pathlib import Path
from zipfile import ZIP_DEFLATED, ZipFile


def main():
    root = Path(__file__).resolve().parents[1]
    parser = argparse.ArgumentParser()
    parser.add_argument('destination', type=Path)
    args = parser.parse_args()
    destination = args.destination.resolve()
    if destination.is_relative_to(root):
        raise SystemExit('Place the archive outside the repository.')

    output = subprocess.check_output(['git', 'ls-files', '-z'], cwd=root)
    files = {root / name.decode('utf-8') for name in output.split(b'\0') if name}
    extra_paths = (
        '.git', 'sqe', 'api', 'tests/Feature/Team', 'tests/Feature/Api/CoreApiLifecycleTest.php',
        'tests/Unit/Team', 'tests/Unit/Document', 'coverage-invoice-focused',
        'coverage-requests-focused', 'coverage-payment-limit-focused',
        'sonar-project.properties', 'infection.json.dist', '.github/workflows/sqe-quality.yml',
        '.github/workflows/sqe-payment-regression.yml',
    )
    for name in extra_paths:
        item = root / name
        if item.is_file():
            files.add(item)
        elif item.is_dir():
            files.update(item.rglob('*'))
    files.update(root.glob('phpunit.*.xml'))
    files.update(root.glob('*-results*.xml'))

    ignored_dirs = {'node_modules', '__pycache__', 'test-results', 'playwright-report', '.scannerwork'}
    ignored_files = {'.env', 'fixtures.json', 'fixtures.local.json', 'index.lock', 'test-report.pdf'}
    def allowed(file):
        relative = file.relative_to(root)
        return (file.is_file() and not file.is_symlink() and file.name not in ignored_files
                and not ignored_dirs.intersection(relative.parts))

    destination.parent.mkdir(parents=True, exist_ok=True)
    with ZipFile(destination, 'w', compression=ZIP_DEFLATED, compresslevel=6) as archive:
        for file in sorted(filter(allowed, files)):
            archive.write(file, (Path('akaunting') / file.relative_to(root)).as_posix())
    with ZipFile(destination) as archive:
        bad = archive.testzip()
        assert bad is None, f'Archive corruption at {bad}'
        assert 'akaunting/.git/HEAD' in archive.namelist()
        assert 'akaunting/sqe/report.html' in archive.namelist()
    with destination.open('rb') as stream:
        checksum = hashlib.file_digest(stream, 'sha256').hexdigest()
    print(f'{destination}\nSHA-256 {checksum}')


if __name__ == '__main__':
    main()
