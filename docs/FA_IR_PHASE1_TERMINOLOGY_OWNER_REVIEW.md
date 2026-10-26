# GFBRC — fa_IR Phase 1 Owner terminology decision

**Owner-approved terminology — 2026-10-26.** The Owner explicitly accepted the five terminology recommendations and the proposed NEW/ACK button and warning. This is **wording approval only**, not Phase 2/3 acceptance, permission to merge, or permission to publish/deploy.

| Concept | Approved Persian label | Operational boundary |
| --- | --- | --- |
| Entry | ورودی | One Gravity Forms submission; not one uploaded file |
| Legacy / NEW / ALL | Keep the English identifiers, explain each in Persian | Legacy is the original Bulk Download; NEW is creation-based and requires receipt acknowledgment; ALL is independent whole-form selection |
| Coverage | سابقهٔ پوشش NEW | Server-recorded eligible Entry coverage; *not* proof that a ZIP was saved locally |
| Flat ZIP | ZIP بدون پوشه‌بندی | Eligible files in the ZIP root, not grouped into Entry subfolders |
| NEW / ACK button | تأیید دریافت و ثبت در سابقهٔ NEW | Explicit and irreversible; not the same event as Download or browser hash verification |

## Owner-approved acknowledgment warning

> با تأیید این عملیات، ورودی‌های این نوبت در دانلودهای بعدی NEW دوباره نمایش داده نمی‌شوند. این اقدام برگشت‌ناپذیر است. فقط پس از اطمینان از ذخیره‌شدن ZIP روی دستگاه خود، دریافت را تأیید کنید.

**Necessary zero-file safety clarification added by the executor:** when the batch has no ZIP, the user must explicitly review the Entries without files rather than being told to save a nonexistent ZIP. This clarification preserves the existing acceptance contract; the Owner approved the core warning above, not this additional sentence verbatim.

The existing `NEW` explanatory gettext message displays this warning in the read-only NEW guide **before** the receipt-confirmation button; the ZIP verification message also reminds users to check their saved copy. No change to actual acknowledgment/coverage logic or persistence is authorized by this wording approval.

## Other existing instructions and safeguards remain in force

- Selected: checked rows on the current Entries page only.
- All matching: current search/filter across pages, maximum 500 Entries; it is not ALL.
- NEW: creation-based, initially includes already-existing eligible Entries; checking or downloading alone does not advance acknowledgment. Entries without files still need explicit review.
- ALL: all active Entries, independent of the Entry List search/filter/selection and NEW acknowledgment history; it **does not** advance the NEW coverage record.
- Legacy/Single Field/Composite and effective form settings remain WordPress-native and unchanged.
- Preserve tokens `NEW`, `ALL`, `Legacy`, `ZIP`, `SHA-256`, `GFAPI`, `InnoDB`, `POST`, `COMMIT`, `ID`, `d[COUNTER].extension` and all PHP formatting placeholders.

**Not authorized or NOT_PROVEN:** Owner-site use and comprehension, complete screen-reader acceptance, independent Phase 2 regression verification, Phase 3 Engineering Compass review, historical ALL race stability, Merge, Release, Tag or deployment. PO/MO and existing locale assertions must pass exact-head checks before review.
