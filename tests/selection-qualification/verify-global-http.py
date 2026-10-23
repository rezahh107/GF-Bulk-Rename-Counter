#!/usr/bin/env python3
"""Authentication, sealed-scope and count contracts through genuine HTTP."""
import html.parser
import io
import json
import pathlib
import re
import subprocess
import sys
import zipfile

lab = pathlib.Path(sys.argv[1])
meta = json.loads((lab / "independent.json").read_text())
endpoint = meta["endpoint"]
cookies = lab / "auth.cookies"
list_url = endpoint.replace("/admin-post.php", "/admin.php")


class ConfigParser(html.parser.HTMLParser):
    def __init__(self):
        super().__init__()
        self.config = None

    def handle_starttag(self, tag, attrs):
        attributes = dict(attrs)
        if tag == "script" and attributes.get("id") == "gfbrc-independent-flat-zip":
            self.config = json.loads(attributes["data-config"])


def selection_config(case, suffix=""):
    fid = meta["cases"][case]["form_id"]
    url = f"{list_url}?page=gf_entries&id={fid}{suffix}"
    response = subprocess.run(["curl", "--fail", "--silent", "--show-error",
                               "--cookie", str(cookies), url], check=True,
                              capture_output=True, text=True).stdout
    parser = ConfigParser()
    parser.feed(response)
    assert parser.config is not None, ("Entry List missing GFBRC config", case)
    return parser.config


def post(case, config, *, form_id=None, nonce=None, scope=None, seal=None,
         mode="all_matching", cookie=cookies, ids=None, expected=None):
    fid = form_id if form_id is not None else meta["cases"][case]["form_id"]
    args = ["curl", "--silent", "--show-error", "-D", "-", "-o", "/dev/null",
            "--max-time", "80", "--cookie", str(cookie),
            "--data-urlencode", "action=gfbrc_flat_zip_download",
            "--data-urlencode", f"form_id={fid}",
            "--data-urlencode", "_wpnonce=" + (nonce if nonce is not None else config["nonce"]),
            "--data-urlencode", "selection_mode=" + mode]
    if scope is not False:
        args += ["--data-urlencode", "selection_scope=" + (
            config["scope"] if scope is None else scope)]
    if seal is not False:
        args += ["--data-urlencode", "selection_scope_seal=" + (
            config["scopeSeal"] if seal is None else seal)]
    if expected is not None:
        args += ["--data-urlencode", "expected_entry_count=" + str(expected)]
    if ids:
        for item in ids:
            args += ["--data-urlencode", "entry_ids[]=" + str(item)]
    args.append(endpoint)
    r = subprocess.run(args, capture_output=True, text=True, check=True)
    headers = r.stdout.lower()
    m = re.findall(r"^http/\S+\s+(\d+)", headers, re.M)
    assert m, ("HTTP headers unavailable", headers[:150])
    return int(m[-1]), headers


def refused(label, *args, expect=None, **kwargs):
    code, headers = post(*args, **kwargs)
    assert code == expect if expect else code in (400, 403, 422), (label, code)
    assert "content-disposition: attachment;" not in headers, (label, headers)
    print(f"PASS_GLOBAL_FAIL_CLOSED {label} HTTP={code}", flush=True)


all_cfg = selection_config("global154")
assert all_cfg["scopeSeal"] and all_cfg["scope"] == "[]" and all_cfg["matchingCount"] == 154
status, hdr = post("global154", all_cfg)
assert status == 200, ("all154", status)
assert re.search(r"^x-gfbrc-selected-entries:\s*154\s*$", hdr, re.M), hdr
assert re.search(r"^x-gfbrc-zip-files:\s*155\s*$", hdr, re.M), hdr
print("PASS_HTTP_GLOBAL_154_ENTRIES_155_FILES", flush=True)

filter_cfg = selection_config("global_filtered", "&field_id=5&s=group-a&operator=is")
assert filter_cfg["scopeSeal"] and "group-a" in filter_cfg["scope"] and filter_cfg["matchingCount"] == 77
status, hdr = post("global_filtered", filter_cfg)
assert status == 200, ("filtered", status, hdr[:300])
assert re.search(r"^x-gfbrc-selected-entries:\s*77\s*$", hdr, re.M), hdr
assert re.search(r"^x-gfbrc-zip-files:\s*78\s*$", hdr, re.M), hdr
print("PASS_HTTP_GLOBAL_FILTER_77_ENTRIES_78_FILES", flush=True)

empty_cfg = selection_config("global_empty")
refused("zero_files", "global_empty", empty_cfg, expect=422)
refused("stale_displayed_count", "global154", all_cfg, expected=153, expect=422)
refused("wrong_scope_sig", "global154", all_cfg, scope='{"filter":"spam"}', expect=403)
refused("missing_seal", "global154", all_cfg, seal=False, expect=403)
refused("missing_scope", "global154", all_cfg, scope=False, expect=403)
refused("wrong_nonce", "global154", all_cfg, nonce="forged", expect=403)
refused("cross_form", "global_empty", all_cfg,
        form_id=meta["cases"]["global_empty"]["form_id"],
        nonce=empty_cfg["nonce"], expect=403)
refused("explicit_ids_in_global", "global154", all_cfg,
        ids=meta["cases"]["global154"]["entry_ids"][:1], expect=400)
refused("invalid_mode", "global154", all_cfg, mode="impossible", expect=400)
refused("unauthorized_user", "global154", all_cfg,
        cookie=lab / "subscriber.cookies", expect=403)
print("PASS_GLOBAL_SCOPE_NONCE_AUTH_AND_NO_FILE_CONTRACTS", flush=True)
