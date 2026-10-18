#!/usr/bin/env python3
"""Independent HTTP / ZIP byte checks against pinned real WP/GF (CI only)."""
import collections
import hashlib
import json
import pathlib
import re
import subprocess
import sys
import time
import zipfile

lab = pathlib.Path(sys.argv[1])
meta = json.loads((lab / "independent.json").read_text())
plans = json.loads((lab / "independent-plans.json").read_text())
cookies = lab / "auth.cookies"
endpoint = meta["endpoint"]
successes = {
    "one": 1, "n99": 99, "n100": 100, "n101": 101,
    "multiple": 3, "shared": 2, "sanitized_equal": 2,
    "multi_entry_files": 5, "host_folder_collision": 2,
    "custom_folder_mixed": 2, "entry_folder_mixed_multi": 5,
    "zero_counter": 1, "empty_counter": 1, "missing_counter": 1,
    "malformed_counter": 1, "larger_workload": 24,
    "repeated_physical": 3, "previously_deleted": 1,
}
failures = {"all_deleted", "missing_mixed", "traversal", "remote_url",
            "unreadable", "cross_form", "duplicate_selected_id"}
assert meta["threshold"] == 100, f"GF background threshold changed: {meta['threshold']}"
assert set(successes) | failures == set(meta["cases"]), "Unexpected scenario inventory"

def request(label, case, cookie_file, nonce, *, expect_zip):
    selected = meta["cases"][case]
    body = lab / (label + ".body")
    headers = lab / (label + ".headers")
    args = ["curl", "--silent", "--show-error", "--location", "--max-redirs", "3",
            "--max-time", "90", "-D", str(headers), "-o", str(body),
            "-w", "%{http_code}", "--request", "POST", "--cookie", str(cookie_file),
            "--data-urlencode", "action=gfbrc_experimental_flat_zip",
            "--data-urlencode", f"form_id={selected['form_id']}",
            "--data-urlencode", f"_wpnonce={nonce}"]
    for entry_id in selected["entry_ids"]:
        args += ["--data-urlencode", f"entry_ids[]={entry_id}"]
    t = time.monotonic()
    result = subprocess.run(args + [endpoint], check=True, capture_output=True, text=True)
    seconds = time.monotonic() - t
    status = result.stdout.strip().splitlines()[-1]
    hdr = headers.read_text(errors="replace").lower()
    payload = body.read_bytes()
    if expect_zip:
        assert status == "200", (label, status, hdr[-700:], payload.decode("utf-8", "replace")[:1800])
        assert "content-disposition: attachment;" in hdr, (label, hdr[-500:])
        assert "content-type: application/zip" in hdr
        assert payload[:2] == b"PK", (label, payload[:100])
    else:
        assert status != "200" or b"PK" != payload[:2], (label, status, payload[:100])
        assert "content-disposition: attachment;" not in hdr
    return body, len(payload), status, seconds

def check_zip(label, case, body):
    plan = plans[case]
    assert isinstance(plan, list), (case, plan)
    assert len(plan) == successes[case], (case, len(plan), successes[case])
    selected = meta["cases"][case]["entry_ids"]
    assert set(x["entry_id"] for x in plan) == set(selected), (case, selected)
    with zipfile.ZipFile(body) as archive:
        members = archive.infolist()
        assert archive.testzip() is None, label
        assert len(members) == len(plan), (label, len(members), len(plan))
        actual = [m.filename for m in members]
        expected = [p["name"] for p in plan]
        assert actual == expected, (label, actual[:6], expected[:6])
        assert len(actual) == len(set(x.casefold() for x in actual)), label
        assert all("/" not in x and "\\" not in x for x in actual), label
        for member, source in zip(members, plan):
            h = hashlib.sha256()
            with archive.open(member) as f:
                while chunk := f.read(65536):
                    h.update(chunk)
            assert member.file_size == source["size"]
            assert h.hexdigest() == source["sha256"]
            assert hashlib.sha256(pathlib.Path(source["source"]).read_bytes()).hexdigest() == source["sha256"]
    # Explicit independent behavioral expectations beyond same-code plan checks.
    if case == "one": assert actual == ["d125.jpg"]
    if case in ("zero_counter", "empty_counter", "missing_counter"): assert actual == ["single-a.jpg"]
    if case == "malformed_counter": assert actual == ["dAB.jpg"]
    if case == "larger_workload": assert len(actual) == 24 and sum(m.file_size for m in members) == 24 * 262144
    if case == "repeated_physical": assert len(set(actual)) == 3
    if case == "sanitized_equal": assert "dAB.jpg" in actual and len(actual) == 2
    if case == "host_folder_collision": assert len(actual) == 2 and len(set(actual)) == 2
    if case in ("n99", "n100", "n101"):
        assert len({x["entry_id"] for x in plan}) == successes[case]
    return len(actual)

for case in successes:
    filename, byte_count, status, sec = request(case, case, cookies, meta["nonces"][str(meta["cases"][case]["form_id"])], expect_zip=True)
    count = check_zip(case, case, filename)
    print(f"PASS_HTTP_FLAT_ZIP {case} entries={len(meta['cases'][case]['entry_ids'])} "
          f"members={count} response_bytes={byte_count} time_s={sec:.3f}", flush=True)

for case in failures:
    if case != "duplicate_selected_id":
        assert "error" in plans[case], (case, plans[case])
    filename, byte_count, status, sec = request(case, case, cookies, meta["nonces"][str(meta["cases"][case]["form_id"])], expect_zip=False)
    print(f"PASS_NO_INCOMPLETE_ZIP {case} status={status} body_bytes={byte_count}", flush=True)

filename, _, status, _ = request("bad-nonce", "one", cookies, "invalid-nonce", expect_zip=False)
assert status == "403", status
print("PASS_INVALID_NONCE_REJECTED", flush=True)

filename, _, status, _ = request("anonymous", "one", "/dev/null", meta["nonces"][str(meta["cases"]["one"]["form_id"])], expect_zip=False)
assert status in {"302", "403", "200", "404"}, status
print("PASS_ANONYMOUS_NO_ZIP", flush=True)

# Read the authenticated Entry list and assert that this test-only feature is
# truly present on its real admin HTML surface rather than just a filter probe.
list_url = endpoint.replace("/admin-post.php", "/admin.php")
args = ["curl", "-fsSL", "--cookie", str(cookies),
        "--get", "--data-urlencode", "page=gf_entries",
        "--data-urlencode", f"id={meta['cases']['one']['form_id']}", list_url]
page = subprocess.run(args, capture_output=True, text=True, check=True).stdout
assert "gfbrc-independent-flat-zip" in page, "Independent interception script absent from actual list"
assert "Download Flat ZIP with Rename" in page, "Bulk menu action absent from actual entry-list page"
print("PASS_AUTHENTIC_ENTRY_LIST_RENDERED", flush=True)
print("NATIVE_GF_THRESHOLD_100_BOUNDARY: separate synchronous POST endpoint exercised for 99/100/101", flush=True)
