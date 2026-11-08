#!/usr/bin/env python3
"""Independent GF-persisted source-occurrence vs actual downloaded ZIP oracle.

The expected JSON is read from GFAPI plus physical source bytes by the isolated
PHP probe. Neither Flat_Zip_Download::plan nor NEW state produces expectations.
The legacy single-file command remains available for PR #50's original proof.
"""
import collections
import hashlib
import json
import pathlib
import re
import sys
import zipfile


def sha(payload):
    return hashlib.sha256(payload).hexdigest()


def legacy(args):
    archive, expected_sha, expected_size, expected_count = args
    with zipfile.ZipFile(archive) as z:
        files = z.infolist()
        assert len(files) == int(expected_count), ("omitted-or-extra-file", len(files))
        names = [f.filename for f in files]
        assert len(set(n.casefold() for n in names)) == len(names), "colliding-archive-names"
        assert all("/" not in n and "\\" not in n for n in names), "nonflat-zip"
        assert int(expected_count) == 1, "Legacy proof is intentionally single-file"
        payload = z.read(files[0])
        assert len(payload) == int(expected_size) == files[0].file_size, "source byte size mismatch"
        assert sha(payload) == expected_sha, "source SHA-256 mismatch"
    print("PASS_REAL_FORM_UPLOAD_ZIP_CONSERVATION", len(files), sha(pathlib.Path(archive).read_bytes()))


def expected_names(files):
    # This isolated form has no custom naming rule or legacy counter. Only
    # ASCII-safe original basenames are used. Derive names independently from
    # GF-stored source basenames, never by reading a plugin ZIP plan.
    reserved = {f["basename"].casefold() for f in files}
    used = set()
    result = {}
    for f in files:
        base = f["basename"]
        assert re.fullmatch(r"[A-Za-z0-9._-]+", base), ("non-fixture-source-name", base)
        name = base
        if name.casefold() in used:
            p = pathlib.PurePosixPath(base)
            suffix = p.suffix
            stem = base[:-len(suffix)] if suffix else base
            for n in range(10001):
                candidate = stem + "-" + str(f["entry_id"]) + (("-" + str(n)) if n else "") + suffix
                if candidate.casefold() not in used and candidate.casefold() not in reserved:
                    name = candidate
                    break
            else:
                raise AssertionError("unable to disambiguate repeated source names")
        used.add(name.casefold())
        result[name] = f
    return result


def verify(expected_path, archive_path):
    payload = json.loads(pathlib.Path(expected_path).read_text(encoding="utf-8"))
    files = payload["files"]
    assert len(files) >= 2, "multi-file expected population is required"
    identities = []
    for f in files:
        ident = (f["form_id"], f["entry_id"], f["field_id"], f["slot"], f["url"])
        assert f["form_id"] == payload["form_id"] and f["entry_id"] == payload["entry_id"], "wrong expected identity"
        assert isinstance(f["size"], int) and f["size"] >= 0, "invalid expected size"
        assert re.fullmatch("[0-9a-f]{64}", f["sha256"]), "invalid source digest"
        identities.append(ident)
    assert len(set(identities)) == len(files), "duplicate expected file occurrence"
    expected = expected_names(files)
    assert len(expected) == len(files), "expected archive name collision"
    actual_counts = collections.Counter()
    with zipfile.ZipFile(archive_path) as archive:
        infos = archive.infolist()
        names = [i.filename for i in infos]
        assert len(set(n.casefold() for n in names)) == len(names), "colliding-archive-names"
        assert all(not i.is_dir() and "/" not in i.filename and "\\" not in i.filename
                   for i in infos), "nonflat-zip"
        actual = set(names)
        missing = sorted(set(expected) - actual)
        extra = sorted(actual - set(expected))
        assert not missing and not extra, ("occurrence missing or extra", missing, extra)
        assert len(infos) == len(files), ("occurrence count mismatch", len(infos), len(files))
        for info in infos:
            f = expected[info.filename]
            content = archive.read(info)
            assert len(content) == f["size"] == info.file_size, ("occurrence size mismatch", info.filename)
            content_sha = sha(content)
            assert content_sha == f["sha256"], ("occurrence byte mismatch", info.filename)
            actual_counts[(content_sha, len(content))] += 1
    expected_counts = collections.Counter((f["sha256"], f["size"]) for f in files)
    assert expected_counts == actual_counts, "file-byte occurrence multiplicity mismatch"
    print("PASS_REAL_FORM_MULTIFIELD_OCCURRENCE_ZIP_CONSERVATION", len(files),
          len(expected_counts), sha(pathlib.Path(archive_path).read_bytes()))


def mutate(archive_path, output_path, mode):
    assert mode in ("omit-one", "corrupt-one"), "invalid mutation"
    source, output = pathlib.Path(archive_path), pathlib.Path(output_path)
    assert source.resolve() != output.resolve(), "cannot mutate original ZIP"
    with zipfile.ZipFile(source) as initial, zipfile.ZipFile(output, "w", zipfile.ZIP_DEFLATED) as modified:
        infos = initial.infolist()
        assert len(infos) >= 3, "mutation requires multi-file source ZIP"
        for index, member in enumerate(infos):
            if mode == "omit-one" and index == len(infos) - 1:
                continue
            data = initial.read(member)
            if mode == "corrupt-one" and index == 0:
                data = (bytes([data[0] ^ 1]) + data[1:]) if data else b"fault"
            modified.writestr(member.filename, data)
    with zipfile.ZipFile(output) as probe:
        assert probe.testzip() is None, "mutated ZIP must remain structurally valid"
    print("PASS_VALID_MUTATED_ZIP_CREATED", mode)


if __name__ == "__main__":
    try:
        arguments = sys.argv[1:]
        if len(arguments) == 4 and arguments[0] not in ("verify", "mutate"):
            legacy(arguments)
        elif len(arguments) == 3 and arguments[0] == "verify":
            verify(arguments[1], arguments[2])
        elif len(arguments) == 4 and arguments[0] == "mutate":
            mutate(arguments[1], arguments[2], arguments[3])
        else:
            raise AssertionError("usage: oracle verify EXPECTED.json ZIP | mutate ZIP OUTPUT mode")
    except (AssertionError, ValueError, KeyError, OSError, zipfile.BadZipFile) as error:
        print("ORACLE_REJECTED", repr(error), file=sys.stderr)
        sys.exit(2)
