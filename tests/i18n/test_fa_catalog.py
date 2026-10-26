"""Persian source catalog, placeholders and compiled MO parity (not WP integration)."""
import gettext
import json
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[2]
DOMAIN = "gf-bulk-rename-counter"
PO = ROOT / "languages" / (DOMAIN + "-fa_IR.po")
POT = ROOT / "languages" / (DOMAIN + ".pot")
MO = ROOT / "languages" / (DOMAIN + "-fa_IR.mo")
PAIR = re.compile(r'^msgid ("(?:\\.|[^"\\])*")\nmsgstr ("(?:\\.|[^"\\])*")$', re.M)
PLACEHOLDER = re.compile(r'%(?:[0-9]+\$)?[sd]')


def pairs(path):
    found = {}
    for raw_id, raw_text in PAIR.findall(path.read_text(encoding="utf-8")):
        english, translated = json.loads(raw_id), json.loads(raw_text)
        if english:
            if english in found:
                raise AssertionError("Duplicate catalog entry: " + english)
            found[english] = translated
    return found


class PersianCatalog(unittest.TestCase):
    def test_full_coverage_placeholder_parity_and_compiled_mo(self):
        template = pairs(POT)
        translated = pairs(PO)
        self.assertGreaterEqual(len(template), 280)
        self.assertEqual(set(template), set(translated))
        with MO.open("rb") as handle:
            binary = gettext.GNUTranslations(handle)
        for english, persian in translated.items():
            with self.subTest(message=english):
                self.assertTrue(persian.strip())
                self.assertEqual(sorted(PLACEHOLDER.findall(english)),
                                 sorted(PLACEHOLDER.findall(persian)))
                self.assertEqual(binary.gettext(english), persian)

    def test_php_source_matches_gettext_catalog(self):
        pattern = re.compile(r"\b(?:__|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\(\s*'((?:\\.|[^'\\])*)'\s*,\s*'gf-bulk-rename-counter'")
        ids = set()
        for path in [ROOT / "gf-bulk-rename-counter.php", *(ROOT / "includes").glob("*.php")]:
            ids.update(pattern.findall(path.read_text(encoding="utf-8")))
        self.assertEqual(ids, set(pairs(POT)))

    def test_safety_meanings_and_identifiers(self):
        translated = pairs(PO)
        checks = {
            "Confirm receipt and advance NEW": ("تأیید دریافت", "ثبت در سابقهٔ NEW"),
            "NEW includes newly created active Entries not yet acknowledged. Checking does not advance coverage. After downloading, verify your local copy before confirming receipt; confirmation is a separate irreversible coverage action.": ("برگشت‌ناپذیر", "ذخیره‌شدن ZIP روی دستگاه خود", "ورودی‌های بدون فایل"),
            "ALL includes eligible uploads from every active Entry in this form, regardless of filters, selected rows or NEW acknowledgment history. Check before downloading; this does not advance NEW coverage.": ("سابقهٔ پوشش NEW را تغییر نمی‌دهد", "همهٔ ورودی‌های فعال"),
            "Legacy uses %1$s (field %2$d) and generates d[COUNTER].extension. Entries with an empty or zero counter retain their original filename.": ("d[COUNTER].extension", "%1$s", "%2$d"),
            "Downloaded ZIP SHA-256 mismatch": ("SHA-256", "ZIP"),
        }
        for english, pieces in checks.items():
            for piece in pieces:
                self.assertIn(piece, translated[english])

    def test_owner_approved_terms_and_critical_pre_ack_warning(self):
        translated = pairs(PO)
        self.assertEqual(translated["Confirm receipt and advance NEW"],
                         "تأیید دریافت و ثبت در سابقهٔ NEW")
        warning = translated[
            "NEW includes newly created active Entries not yet acknowledged. Checking does not advance coverage. After downloading, verify your local copy before confirming receipt; confirmation is a separate irreversible coverage action."]
        for sentence in (
            "با تأیید این عملیات، ورودی‌های این نوبت در دانلودهای بعدی NEW دوباره نمایش داده نمی‌شوند.",
            "این اقدام برگشت‌ناپذیر است.",
            "فقط پس از اطمینان از ذخیره‌شدن ZIP روی دستگاه خود، دریافت را تأیید کنید.",
            "اگر این نوبت فایل واجد شرایطی ندارد",
            "ورودی‌های بدون فایل را صریحاً بازبینی کنید.",
        ):
            self.assertIn(sentence, warning)
        self.assertIn("سابقهٔ پوشش NEW را تغییر نمی‌دهد",
            translated["ALL includes eligible uploads from every active Entry in this form, regardless of filters, selected rows or NEW acknowledgment history. Check before downloading; this does not advance NEW coverage."])
        self.assertEqual(translated["Download Flat ZIP with Rename"],
                         "دانلود ZIP بدون پوشه‌بندی با تغییر نام")
        for english, localized in translated.items():
            with self.subTest(english=english):
                self.assertNotIn("ZIP تخت", localized)

    def test_result_tokens_are_not_translated_into_ack_status(self):
        translated = pairs(PO)
        self.assertEqual(translated["Field %d"], "فیلد %d")
        expected = {
            "NEW: Receipt/review confirmation recorded in NEW coverage (Entry count: %d). Check NEW again for later Entries.": (
                "سابقهٔ پوشش NEW ثبت شد", "برای ورودی‌های بعدی"),
            "NEW: Receipt/review for this batch was already confirmed (Entry count: %d). NEW coverage was not advanced again.": (
                "پیش‌تر ثبت شده است", "دوباره تغییر نکرد"),
        }
        for english, required in expected.items():
            self.assertIn("%d", translated[english])
            for phrase in required:
                self.assertIn(phrase, translated[english])
            self.assertNotIn("acknowledged", translated[english])
            self.assertNotIn("duplicate", translated[english])
        self.assertNotIn(
            "NEW %1$s: %2$d Entries confirmed. Check NEW again for later Entries.",
            translated)


if __name__ == "__main__":
    unittest.main()
