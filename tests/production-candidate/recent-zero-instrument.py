#!/usr/bin/env python3
"""Temporarily instrument only the installed lab copy at the exact cache call."""
import os
import pathlib
import sys
import tempfile

SOURCE = pathlib.Path('includes/class-recent_download.php')
TARGET = pathlib.Path(os.environ['BRC_WP_PATH']) / 'wp-content/plugins/gf-bulk-rename-counter/includes/class-recent_download.php'
BACKUP = pathlib.Path(os.environ['BRC_LAB_DIR']) / 'recent-zero-original.php'
ANCHOR = b'            Flat_Zip_Download::clear_deletion_meta_cache($ids);\n'
PROBE = r'''            // TEST-ONLY: record the untouched global immediately before cache eviction.
            $gfbrc_probe_present = array_key_exists('_gform_lead_meta', $GLOBALS);
            $gfbrc_probe_value = $gfbrc_probe_present ? $GLOBALS['_gform_lead_meta'] : null;
            $gfbrc_probe_record = [
                'form' => $form, 'ids' => count($ids), 'defined' => $gfbrc_probe_present,
                'type' => $gfbrc_probe_present ? get_debug_type($gfbrc_probe_value) : 'undefined',
                'items' => is_array($gfbrc_probe_value) ? count($gfbrc_probe_value) : null,
            ];
            if (file_put_contents(getenv('BRC_LAB_DIR') . '/recent-zero-cache.jsonl',
                    wp_json_encode($gfbrc_probe_record) . "\n", FILE_APPEND | LOCK_EX) === false) {
                throw new \RuntimeException('Test-only cache boundary observation failed');
            }
'''.encode('utf-8')


def atomic_write(path: pathlib.Path, content: bytes) -> None:
    with tempfile.NamedTemporaryFile(dir=path.parent, prefix='.gfbrc-zero-', delete=False) as out:
        tmp = pathlib.Path(out.name)
        out.write(content)
    try:
        tmp.chmod(path.stat().st_mode & 0o777)
        os.replace(tmp, path)
    finally:
        tmp.unlink(missing_ok=True)


def main() -> None:
    if len(sys.argv) != 2 or sys.argv[1] not in ('apply', 'restore'):
        raise SystemExit('usage: recent-zero-instrument.py apply|restore')
    mode = sys.argv[1]
    if mode == 'apply':
        if BACKUP.exists():
            raise RuntimeError('Refusing to overwrite an existing test-only backup')
        original = SOURCE.read_bytes()
        installed = TARGET.read_bytes()
        if original != installed or installed.count(ANCHOR) != 1:
            raise RuntimeError('Installed plugin differs from exact source or cache anchor is not unique')
        with BACKUP.open('xb') as backup:
            backup.write(installed)
        try:
            atomic_write(TARGET, installed.replace(ANCHOR, PROBE + ANCHOR, 1))
        except BaseException:
            atomic_write(TARGET, installed)
            BACKUP.unlink(missing_ok=True)
            raise
        print('GFBRC_ZERO_TEST_ONLY_PROBE_APPLIED')
    else:
        original = BACKUP.read_bytes()
        if original != SOURCE.read_bytes():
            raise RuntimeError('Source changed before restoring original installed plugin')
        atomic_write(TARGET, original)
        if TARGET.read_bytes() != original:
            raise RuntimeError('Failed to restore exact original production bytes')
        BACKUP.unlink()
        print('GFBRC_ZERO_INSTALLED_SOURCE_RESTORED')


if __name__ == '__main__':
    main()
