#!/usr/bin/env python3
"""One-shot mutation of the *installed CI host*; fail closed on source drift."""
from pathlib import Path
import hashlib
import re
import shutil
import sys

EXPECTED = "5fd74e4e842eac2a40ee2395d07f55d85b8aa5499db47e10b0582d09dfa423f3"


def replace_once(text, before, after):
    if text.count(before) != 1:
        raise RuntimeError("Pinned host source seam changed: " + before[:90])
    return text.replace(before, after)


def main():
    if len(sys.argv) != 3:
        raise SystemExit("Usage: patch-host.py installed-BulkDownload.php MethodC.php")
    host, helper = map(Path, sys.argv[1:])
    raw = host.read_bytes()
    if hashlib.sha256(raw).hexdigest() != EXPECTED:
        raise RuntimeError("Bulk Download 4.2.0 installed source mismatch")
    source = raw.decode("utf-8")
    source = replace_once(
        source, "use ZipArchive;\n",
        "use ZipArchive;\nrequire_once __DIR__ . '/MethodC.php';\n"
    )
    original = re.compile(
        r"(public function zip_uploaded_files\( \$uploaded_files, \$zip, \$form \) \{)"
        r".*?(\n\t\}\n\n\t/\*\*\n\t \* Sanitize zip archive filename)",
        re.S,
    )
    replacement = (
        r"\1\n"
        "\t\treturn MethodC::write($uploaded_files, $zip, $form,\n"
        "\t\t\tfn($candidate, $fallback) => $this->sanitize_zip_entry_filename($candidate, $fallback));"
        r"\2"
    )
    source, count = original.subn(replacement, source)
    if count != 1:
        raise RuntimeError("Could not isolate exact original host writer")
    source = replace_once(
        source,
        """\t\t\t\t\tif ( $path && is_readable( $path ) ) {
\t\t\t\t\t\t$uploaded_files[ (int) $entry_id ][] = $path;
\t\t\t\t\t}""",
        """\t\t\t\t\tif ( ! $path || ! is_file( $path ) || ! is_readable( $path ) ) {
\t\t\t\t\t\tthrow new \\RuntimeException( 'METHOD_C_SOURCE_UNAVAILABLE' );
\t\t\t\t\t}
\t\t\t\t\t$uploaded_files[ (int) $entry_id ][] = $path;"""
    )
    source = replace_once(
        source,
        "\t\t\t$this->zip_uploaded_files( $uploaded_files, $zip, $form );",
        "\t\t\t$this->zip_uploaded_files( $uploaded_files, $zip, $form );"
    ) if False else source
    source = replace_once(
        source,
        "\t\t\t$zip_filename = wp_tempnam( $download_filename . '.zip' );",
        """\t\t\t$zip_filename = wp_tempnam( $download_filename . '.zip' );
\t\t\tif ($zip_filename && getenv('BRC_LAB_DIR')) {
\t\t\t\tfile_put_contents(getenv('BRC_LAB_DIR') . '/mc-temp-path.txt', $zip_filename);
\t\t\t}"""
    )
    source = replace_once(
        source,
        "\t\t\tif ( ! $zip->close() ) {",
        """\t\t\tif (getenv('BRC_MC_FAIL_CLOSE') === '1') {
\t\t\t\t$zip->close(); // fault injection AFTER native close, to exercise fail-before-delivery cleanup\n\t\t\t\tthrow new \\Exception('METHOD_C_INJECTED_CLOSE_FAILURE');
\t\t\t}
\t\t\tif ( ! $zip->close() ) {"""
    )
    source = replace_once(
        source,
        "\t\t\tclearstatcache( true, $zip_filename );",
        """\t\t\tMethodC::verify_archive($zip_filename);
\t\t\tclearstatcache( true, $zip_filename );"""
    )
    if not helper.is_file() or host.parent.joinpath("MethodC.php").exists():
        raise RuntimeError("Helper missing or host already mutated")
    shutil.copyfile(helper, host.parent / "MethodC.php")
    host.write_text(source, encoding="utf-8")
    print("EXPERIMENTAL_HOST_ONLY_PATCH_APPLIED; original SHA-256", EXPECTED)
    print("Experimental host SHA-256", hashlib.sha256(host.read_bytes()).hexdigest())


if __name__ == "__main__":
    main()
