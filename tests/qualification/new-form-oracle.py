#!/usr/bin/env python3
"""Independent P0 persisted-source-to-browser-ZIP conservation oracle."""
import hashlib
import pathlib
import sys
import zipfile

archive = pathlib.Path(sys.argv[1])
expected_sha = sys.argv[2]
expected_size = int(sys.argv[3])
expected_count = int(sys.argv[4])
with zipfile.ZipFile(archive) as z:
    files = z.infolist()
    assert len(files) == expected_count, ("omitted-or-extra-file", len(files), expected_count)
    names = [f.filename for f in files]
    assert len(set(x.casefold() for x in names)) == len(names), ("colliding-archive-names", names)
    assert all("/" not in name and "\\" not in name for name in names), ("nonflat-zip", names)
    assert expected_count == 1, "This oracle intentionally checks the real single-upload case"
    payload = z.read(files[0])
    assert len(payload) == expected_size == files[0].file_size, "source byte size mismatch"
    assert hashlib.sha256(payload).hexdigest() == expected_sha, "source SHA-256 mismatch"
print("PASS_REAL_FORM_UPLOAD_ZIP_CONSERVATION", len(files), hashlib.sha256(archive.read_bytes()).hexdigest())
