# راهنمای مالک — انتشار کنترل‌شده افزونه

این مخزن یک افزونه وردپرس با نسخه فعلی `2.0.6` دارد. انتشار فعلی فقط از مسیر **GitHub Releases** تعریف شده است؛ هیچ انتشار در WordPress.org یا نصب روی سایت واقعی انجام نمی‌شود.

## ۱. Dry-run بی‌خطر
در **Actions → Release contract and installable ZIP dry-run → Run workflow** روی `main` اجرا کنید. این عملیات فقط ZIP واقعی، فایل SHA-256 و Manifest را در **Artifacts** همان اجرای CI ایجاد می‌کند. Tag یا GitHub Release نمی‌سازد. CI مربوط به Native WordPress نیز ZIP تولیدشده را واقعاً داخل محیط آزمایشی نصب می‌کند.

## ۲. آماده‌سازی Release Candidate
در **Actions → Prepare reviewed WordPress release candidate → Run workflow** روی `main`، نیت انتشار را انتخاب کنید:
- `first`: فقط زمانی که هیچ Tag/Release قبلی وجود ندارد؛ نخستین انتشار نسخه موجود `2.0.6` است.
- `patch` / `minor` / `major`: وقتی Releaseهای قبلی وجود دارند؛ نسخه بعدی محاسبه می‌شود.

سیستم Branch و PR بازبینی را ایجاد می‌کند و دو تست `release-dry-run` و `native-zip-integration` را روی **Head دقیق Candidate** اجرا می‌کند. پایان این مرحله **به معنی تأیید تست‌ها نیست**: نتیجه هر دو Workflow را در Actions بررسی کنید. سپس تغییرات نسخه، changelog و PR را مرور کنید؛ فقط با تأیید آگاهانه خودتان Merge کنید. اگر Workflow به دلیل تنظیمات دسترسی GitHub نتوانست PR یا CI را ایجاد کند، عملیات متوقف است و باید قابلیت مجاز ساخت PR/اجرای Actions توسط GitHub Actions در تنظیمات مخزن بازبینی شود. برای دورزدن کنترل‌ها توکن دائمی با دسترسی گسترده تعریف نکنید.

## ۳. انتشار واقعی — فقط با اجازه جداگانه
ابتدا در **Settings → General / Releases** قابلیت **Immutable Releases** را فعال کنید. یک Secret به نام `BRC_RELEASE_ADMIN_READ_TOKEN` حاوی توکن کم‌اختیار با دسترسی **Repository Administration: read** تعریف کنید تا وضعیت Immutable Releases به‌صورت ماشینی تأیید شود؛ `GITHUB_TOKEN` مسئول انتشار است.

پس از Merge شدن **همان PR که CI موفق و تأییدشده دارد**، در **Actions → Owner-authorized production publication → Run workflow** روی `main` مقدار `PUBLISH` را وارد کنید. نسخه را دستی وارد نمی‌کنید. سیستم هویت درخت فایل‌های Candidate و `main`، هر دو CI، نسخه، تعارض Tag/Release، امنیت پلتفرم، ZIP و SHA-256 را دوباره بررسی می‌کند، سپس در GitHub Release نسخه را با ZIP و Checksum منتشر می‌کند. ZIP را دوباره از GitHub دانلود، بررسی و در WordPress آزمایشی با وابستگی‌های واقعی نصب می‌کند. در انتها Candidate Marker را از `main` حذف می‌کند، اما Tag منتشرشده ثابت می‌ماند.

## ۴. خطا و بازیابی
تا وقتی **نتیجه مثبت خود Workflow انتشار** ثبت نشده، وضعیت `PUBLISHED_AND_CONSUMER_VERIFIED` را ادعا نکنید. اگر خطا قبل از ساخت Tag رخ داده، علت را رفع و فقط پس از بررسی وضعیت دوباره آماده‌سازی/اجرا کنید. اگر Tag، Draft Release یا Release منتشرشده ایجاد شده است، **همان عملیات را کورکورانه تکرار نکنید**. Tag را جابه‌جا نکنید؛ ZIP یا Checksum منتشرشده را جایگزین نکنید و History را Force Push نکنید. وضعیت ناقص را بررسی کنید و درباره Release اصلاحی با نسخه جدید تصمیم بگیرید.

**محدودیت:** Dry-run و تست مصنوعی وردپرس معادل صحت روی سایت واقعی، محیط واقعی کاربران یا انتشار واقعی نیستند.