"""Audit real Chromium downloads against independently seeded file occurrences."""
import hashlib
import json
from pathlib import Path
import sys
import zipfile

lab = Path(sys.argv[1])
fixture = json.loads((lab / "recent-fixture.json").read_text())


def members(entries):
    return {name: info for entry in entries for name, info in entry["members"].items()}


expected_archives = {str(n): members(fixture["entries"][:n]) for n in (50, 100, 200, 500)}
expected_archives["exact500"] = members(fixture["entries"][1:])
expected_archives["after-ack"] = {"recent-1.txt": fixture["small_source"]}

for label, expected in expected_archives.items():
    archive = lab / f"recent-{label}.zip"
    with zipfile.ZipFile(archive) as zipped:
        names = zipped.namelist()
        assert len(names) == len(expected) and set(names) == set(expected), label
        assert len({name.casefold() for name in names}) == len(expected), label
        assert all("/" not in name and "\\" not in name for name in names), label
        for member in zipped.infolist():
            source = expected[member.filename]
            data = zipped.read(member)
            assert member.file_size == len(data) == source["bytes"], (label, member)
            assert hashlib.sha256(data).hexdigest() == source["sha"], (label, member)
    print("PASS_RECENT_CHROMIUM_ZIP", label, "members", len(expected),
          "sha256", hashlib.sha256(archive.read_bytes()).hexdigest())
