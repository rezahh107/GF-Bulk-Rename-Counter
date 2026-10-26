# GFBRC — fa_IR Phase 1 terminology for Owner review

**Status: TRANSLATION CANDIDATE; NOT OWNER-APPROVED.** Review wording without changing the existing WordPress-native settings, naming rules, or download safety contracts.

| Concept | Current Persian | Owner decision point |
| --- | --- | --- |
| Entry | ورودی | Prefer «ورودی» versus «رکورد» or a business-specific «ثبت‌نام»? One Entry is not one file. |
| Legacy | Legacy (روش موجود/قدیمی) | Keep the English technical identifier? Original Bulk Download action only. |
| Selected | Selected (ردیف‌های انتخاب‌شده) | Current page's checked rows only. |
| All matching | همهٔ موارد مطابق فیلتر | Current search/filter across pages, not the whole form; maximum 500 Entries. |
| NEW | NEW (ورودی‌های تازه‌ساخته‌شده) | Based on creation and unacknowledged coverage, not modified files on older Entries. |
| ACK | تأیید دریافت و پیش‌برد پوشش NEW | Explicit, distinct from browser ZIP check; advancing coverage is irreversible. **Review this label first.** |
| Coverage | پوشش NEW | Server-recorded coverage, not proof of a locally saved ZIP. |
| Initial batch | بستهٔ اولیه | The first check can include existing Entries; it does not auto-acknowledge. |
| Zero-file Entries | ورودی‌های بدون فایل | Explicit review is required, and no synthetic empty ZIP is made. |
| ALL | ALL (کل فرم) | All active Entries regardless of filters, pages, row selection or NEW history; no NEW ACK. |
| Single Field / Composite | تک‌فیلدی / ترکیبی with English term | Independent ZIP naming methods, not global Legacy settings. |
| Flat ZIP | ZIP تخت | No per-Entry folders; filenames can reveal personal data. |
| Verified ZIP / SHA-256 | ZIP راستی‌آزمایی‌شده / SHA-256 | Browser hash verification does not prove local file retention. |
| Counter Field ID | شناسهٔ فیلد شمارنده | 0 deliberately keeps original names for the Legacy path. |

**Owner terminology choices remain OPEN:** «ورودی» vs «رکورد»; whether to retain English \`Legacy\`, \`NEW\`, \`ALL\`; whether «پوشش» is understood; whether «ZIP تخت» should be «ZIP بدون پوشه‌بندی»; the full ACK confirmation text.

Technical tokens retained: \`NEW\`, \`ALL\`, \`ZIP\`, \`Legacy\`, \`SHA-256\`, \`GFAPI\`, \`InnoDB\`, \`POST\`, \`COMMIT\`, \`ID\`, \`d[COUNTER].extension\`; PHP format placeholders retain their ordering/type.

Scope is Phase 1 only. The working PO/MO is not a human-reviewed translation approval. Browser/operator comprehension, screen-reader acceptance, the Owner host, and Phase 2/3 remain NOT_PROVEN.
