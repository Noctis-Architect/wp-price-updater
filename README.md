# Dollar Price Updater PRO

> پلاگین وردپرس برای به‌روزرسانی خودکار قیمت محصولات ووکامرس بر اساس نرخ دلار — با رند هوشمند، اعلان تلگرام، snapshot روزانه و rollback.

> A WordPress plugin that automatically updates WooCommerce product prices based on the USD exchange rate — with smart rounding, Telegram alerts, daily snapshots, and rollback.

**نسخه / Version:** 3.0.1  
**نویسنده / Author:** mr-noctis  
**نیازمندی‌ها / Requires:** WordPress + WooCommerce + PHP (cURL)

---

## فهرست / Table of Contents

- [این پلاگین چه کاری می‌کند؟](#این-پلاگین-چه-کاری-می‌کند)
- [What does this plugin do?](#what-does-this-plugin-do)
- [ویژگی‌ها](#ویژگی‌ها)
- [Features](#features)
- [نصب](#نصب)
- [Installation](#installation)
- [دانلود از Releases](#دانلود-از-releases)
- [Download from Releases](#download-from-releases)
- [شروع سریع](#شروع-سریع)
- [Quick Start](#quick-start)
- [منطق محاسبه قیمت](#منطق-محاسبه-قیمت)
- [Price Calculation Logic](#price-calculation-logic)
- [پنل مدیریت](#پنل-مدیریت)
- [Admin Panel](#admin-panel)
- [تنظیم تلگرام](#تنظیم-تلگرام)
- [Telegram Setup](#telegram-setup)
- [Snapshot و Rollback](#snapshot-و-rollback)
- [Snapshots & Rollback](#snapshots--rollback)
- [ساختار فایل‌ها](#ساختار-فایل‌ها)
- [File Structure](#file-structure)
- [متادیتای محصول](#متادیتای-محصول)
- [Product Metadata](#product-metadata)
- [لاگ و عیب‌یابی](#لاگ-و-عیب‌یابی)
- [Logging & Troubleshooting](#logging--troubleshooting)
- [نکات مهم](#نکات-مهم)
- [Important Notes](#important-notes)

---

## این پلاگین چه کاری می‌کند؟

اگر فروشگاه ووکامرس شما قیمت محصولات را بر اساس دلار تنظیم می‌کند، هر بار که نرخ ارز بالا برود باید قیمت‌ها را دستی عوض کنید. این پلاگین این کار را خودکار می‌کند:

1. **قیمت دلار** را از API دریافت می‌کند.
2. **در زمان‌های مشخص** (مثلاً هر شب ساعت ۰۰:۰۰) یا با یک کلیک، قیمت محصولات را محاسبه و به‌روز می‌کند.
3. **گزارش کامل** را در تلگرام می‌فرستد.
4. **قبل از هر به‌روزرسانی** یک snapshot از قیمت‌ها ذخیره می‌کند تا در صورت نیاز بتوانید برگردید.

---

## What does this plugin do?

If your WooCommerce store prices products based on the USD rate, every time the exchange rate rises you have to update prices manually. This plugin automates that:

1. **Fetches the USD rate** from an external API.
2. **Updates product prices** on a schedule (e.g. every night at 00:00) or with one click.
3. **Sends a detailed report** to Telegram.
4. **Takes a snapshot** before each update so you can roll back if needed.

---

## ویژگی‌ها

| ویژگی | توضیح |
|--------|--------|
| به‌روزرسانی خودکار | زمان‌بندی روزانه با timezone تهران |
| به‌روزرسانی دستی | اجرای فوری از پنل ادمین |
| تنظیم درصدی | افزایش یا کاهش درصدی مستقل از دلار |
| رند هوشمند | رند کردن قیمت بر اساس بازه‌های قابل تنظیم |
| اعلان تلگرام | دو حالت: ربات مستقیم یا Cloudflare Worker |
| Snapshot روزانه | ذخیره قیمت تمام محصولات قبل از هر آپدیت |
| Rollback | بازگردانی قیمت‌ها به یک تاریخ مشخص |
| فیلتر دسته‌بندی | محدود کردن آپدیت به دسته‌های خاص |
| پردازش batch | جلوگیری از timeout در فروشگاه‌های بزرگ |
| نوار ادمین | نمایش قیمت لحظه‌ای دلار در admin bar |

---

## Features

| Feature | Description |
|---------|-------------|
| Automatic updates | Daily scheduling with Asia/Tehran timezone |
| Manual run | Trigger an update instantly from the admin panel |
| Percentage adjust | Increase or decrease prices by a fixed % (independent of USD) |
| Smart rounding | Round prices using configurable price ranges |
| Telegram alerts | Two modes: direct bot or Cloudflare Worker proxy |
| Daily snapshots | Save all product prices before each update |
| Rollback | Restore prices to a specific date |
| Category filter | Limit updates to selected product categories |
| Batch processing | Avoid timeouts on large catalogs |
| Admin bar | Live USD rate in the WordPress admin bar |

---

## نصب

### پیش‌نیازها

- WordPress 5.8+
- WooCommerce 5.0+
- PHP 7.4+ با extension **cURL**
- دسترسی به اینترنت برای API قیمت دلار

### مراحل

1. از بخش **[Releases](https://github.com/Noctis-Architect/wp-price-updater/releases)** آخرین فایل `dollar-price-updater.zip` را دانلود کنید.
2. در وردپرس بروید به **افزونه‌ها → افزودن → بارگذاری افزونه** و همان zip را آپلود کنید.
3. پلاگین **Dollar Price Updater PRO** را فعال کنید.
4. به **WooCommerce → Dollar Updater** بروید.
5. کلید API و تنظیمات تلگرام را وارد کنید.
6. یک بار **اجرای دستی** بزنید تا متادیتای محصولات ساخته شود.

> **ساختار zip:** فایل Release بدون پوشهٔ اضافه است — مستقیماً `dollar-price-updater.php`، `admin/` و `includes/` داخل zip قرار دارند و برای نصب از پنل وردپرس آماده‌اند.

> **نصب دستی:** اگر ترجیح می‌دهید، zip را در `wp-content/plugins/dollar-price-updater/` extract کنید (یک پوشه بسازید و محتوای zip را داخل آن بریزید).

> **نکته:** اولین بار که پلاگین روی محصولات موجود اجرا می‌شود، فقط `_dpu_base_price` و `_dpu_upload_dollar` را ذخیره می‌کند و قیمت را تغییر نمی‌دهد. از اجرای بعدی به بعد، قیمت‌ها بر اساس تغییر دلار به‌روز می‌شوند.

---

## Installation

### Requirements

- WordPress 5.8+
- WooCommerce 5.0+
- PHP 7.4+ with **cURL** extension
- Internet access for the USD price API

### Steps

1. Download the latest `dollar-price-updater.zip` from **[Releases](https://github.com/Noctis-Architect/wp-price-updater/releases)**.
2. In WordPress go to **Plugins → Add New → Upload Plugin** and upload the zip file.
3. Activate **Dollar Price Updater PRO**.
4. Go to **WooCommerce → Dollar Updater**.
5. Enter your API key and Telegram settings.
6. Run a **manual update** once to initialize product metadata.

> **Zip layout:** Release archives have no extra wrapper folder — `dollar-price-updater.php`, `admin/`, and `includes/` sit at the zip root and are ready for WordPress upload.

> **Manual install:** Alternatively, create `wp-content/plugins/dollar-price-updater/` and extract the zip contents there.

> **Note:** On the first run for existing products, the plugin only saves `_dpu_base_price` and `_dpu_upload_dollar` without changing prices. From the second run onward, prices update based on USD changes.

---

## دانلود از Releases

هر نسخهٔ جدید با tag (مثلاً `v3.0.0`) منتشر می‌شود و GitHub Actions به‌صورت خودکار فایل zip نصب‌پذیر می‌سازد.

1. به [Releases](https://github.com/Noctis-Architect/wp-price-updater/releases) بروید.
2. آخرین نسخه را باز کنید.
3. `dollar-price-updater.zip` را دانلود و از **افزونه‌ها → افزودن → بارگذاری افزونه** نصب کنید.

---

## Download from Releases

Each new version is published with a tag (e.g. `v3.0.0`). GitHub Actions builds an installable zip automatically.

1. Open [Releases](https://github.com/Noctis-Architect/wp-price-updater/releases).
2. Pick the latest version.
3. Download `dollar-price-updater.zip` and install it via **Plugins → Add New → Upload Plugin**.

---

## شروع سریع

```
1. API Key را از brsapi.ir بگیرید و در تنظیمات وارد کنید
2. زمان آپدیت را تنظیم کنید (مثلاً: 00:00,08:00)
3. نسبت (Ratio) را روی 0.5 بگذارید (نیمی از تغییر دلار اعمال می‌شود)
4. تلگرام را راه‌اندازی کنید
5. «اجرای دستی» را بزنید
```

---

## Quick Start

```
1. Get an API key from brsapi.ir and enter it in settings
2. Set update times (e.g. 00:00,08:00)
3. Set Ratio to 0.5 (half of the USD change is applied)
4. Set up Telegram notifications
5. Click "Run Now"
```

---

## منطق محاسبه قیمت

### حالت خودکار / دستی (Auto / Manual)

هر محصول دو مقدار مرجع دارد:

- **`_dpu_base_price`** — قیمت پایه (اولین بار که محصول ذخیره می‌شود)
- **`_dpu_upload_dollar`** — نرخ دلار در زمان ثبت قیمت پایه

فرمول:

```
تغییر دلار (%) = (دلار فعلی − دلار زمان ثبت) ÷ دلار زمان ثبت
اگر منفی بود → صفر (قیمت هرگز کاهش پیدا نمی‌کند)

درصد نهایی = تغییر دلار × Ratio
افزایش قیمت = قیمت پایه × درصد نهایی

(در حالت دستی) + قیمت پایه × (Manual Percent ÷ 100)

قیمت جدید = رند(قیمت پایه + افزایش قیمت)
```

**مثال:**  
قیمت پایه = ۱۰,۰۰۰,۰۰۰ تومان — دلار زمان ثبت = ۸۰,۰۰۰ — دلار فعلی = ۸۸,۰۰۰ — Ratio = 0.5

```
تغییر دلار = (88000 − 80000) / 80000 = 10%
درصد نهایی = 10% × 0.5 = 5%
افزایش = 10,000,000 × 5% = 500,000
قیمت جدید = 10,500,000 (بعد از رند)
```

### حالت تنظیم درصدی (Manual Adjust)

در این حالت **هیچ محاسبه‌ای بر اساس دلار انجام نمی‌شود**. فقط درصد مشخص‌شده روی قیمت فعلی اعمال می‌شود:

```
افزایش:  قیمت جدید = قیمت فعلی + (قیمت فعلی × درصد ÷ 100)
کاهش:    قیمت جدید = قیمت فعلی − (قیمت فعلی × درصد ÷ 100)
```

می‌توانید این حالت را به دسته‌بندی‌های خاص محدود کنید.

### رند هوشمند

قیمت نهایی بر اساس بازه‌های تعریف‌شده به پایین رند می‌شود (`floor`):

| حداکثر قیمت (تومان) | گام رند |
|---------------------|---------|
| ۵,۰۰۰,۰۰۰ | ۱۰۰,۰۰۰ |
| ۱۰,۰۰۰,۰۰۰ | ۵۰۰,۰۰۰ |
| ۵۰,۰۰۰,۰۰۰ | ۱,۰۰۰,۰۰۰ |
| ۱۰۰,۰۰۰,۰۰۰ | ۲,۰۰۰,۰۰۰ |
| ۵۰۰,۰۰۰,۰۰۰ | ۵,۰۰۰,۰۰۰ |
| بیشتر | ۱۰,۰۰۰,۰۰۰ |

---

## Price Calculation Logic

### Auto / Manual mode

Each product stores two reference values:

- **`_dpu_base_price`** — base price (saved when the product is first stored)
- **`_dpu_upload_dollar`** — USD rate at the time the base price was saved

Formula:

```
USD change (%) = (current USD − saved USD) ÷ saved USD
If negative → 0 (prices never decrease in auto/manual mode)

Final % = USD change × Ratio
Price increase = base price × final %

(In manual mode) + base price × (Manual Percent ÷ 100)

New price = round(base price + price increase)
```

**Example:**  
Base price = 10,000,000 Toman — Saved USD = 80,000 — Current USD = 88,000 — Ratio = 0.5

```
USD change = (88000 − 80000) / 80000 = 10%
Final % = 10% × 0.5 = 5%
Increase = 10,000,000 × 5% = 500,000
New price = 10,500,000 (after rounding)
```

### Manual Adjust mode

No USD calculation. A fixed percentage is applied to the **current** price:

```
Increase:  new price = current + (current × percent ÷ 100)
Decrease:  new price = current − (current × percent ÷ 100)
```

Can be limited to specific product categories.

### Smart rounding

Final prices are rounded **down** (`floor`) using configurable ranges (see table above).

---

## پنل مدیریت

مسیر: **WooCommerce → Dollar Updater**

| تب | کاربرد |
|----|--------|
| 📊 داشبورد | وضعیت دلار، snapshot، زمان‌بندی، اجرای دستی |
| ⚙️ تنظیمات | API، زمان‌بندی، Ratio، دسته‌بندی، کش |
| 📱 تلگرام | حالت Worker یا Direct، تنظیمات ارسال |
| 🎯 رند کردن | فعال/غیرفعال و جدول بازه‌های رند |
| 🔧 تنظیم قیمت | افزایش/کاهش درصدی مستقل |
| 🔙 برگشت قیمت | لیست snapshot‌ها و rollback |
| 🚀 ابزارها | تست API، پاک کردن کش، snapshot دستی، ارسال لیست محصولات |

### تنظیمات کلیدی

| تنظیم | پیش‌فرض | توضیح |
|-------|---------|--------|
| API URL | `brsapi.ir/Api/Market/Gold_Currency.php` | آدرس API قیمت ارز |
| API Key | — | کلید دریافت از brsapi.ir |
| Cache TTL | 3600 ثانیه | مدت کش قیمت دلار |
| Update Times | `00:00` | ساعت‌های اجرا (جداشده با کاما، timezone تهران) |
| Ratio | 0.5 | سهم تغییر دلار در قیمت (۰ تا ۱) |
| Manual Percent | 0 | درصد اضافه در اجرای دستی |
| Limit Categories | — | ID دسته‌ها (جداشده با کاما) |

---

## Admin Panel

Path: **WooCommerce → Dollar Updater**

| Tab | Purpose |
|-----|---------|
| 📊 Dashboard | USD status, snapshots, schedule, manual run |
| ⚙️ Settings | API, schedule, ratio, categories, cache |
| 📱 Telegram | Worker or Direct mode settings |
| 🎯 Rounding | Enable/disable and configure rounding ranges |
| 🔧 Price Adjust | Percentage increase/decrease (independent) |
| 🔙 Rollback | Snapshot list and restore |
| 🚀 Tools | Test API, clear cache, manual snapshot, send product list |

See the settings table above for key options.

---

## تنظیم تلگرام

دو روش برای ارسال پیام وجود دارد:

### روش ۱: Cloudflare Worker (پیشنهادی)

اگر سرور شما به `api.telegram.org` دسترسی ندارد، از Worker استفاده کنید.

1. در [Cloudflare Dashboard](https://dash.cloudflare.com) یک Worker بسازید.
2. کد `admin/worker-template.js` را deploy کنید.
3. `BOT_TOKEN` را با توکن ربات تلگرام جایگزین کنید.
4. URL Worker و Chat ID را در تب تلگرام پلاگین وارد کنید.

### روش ۲: Direct Bot

اگر سرور مستقیم به Telegram API دسترسی دارد:

1. از [@BotFather](https://t.me/BotFather) یک ربات بسازید.
2. Chat ID کانال/گروه را پیدا کنید.
3. Token و Chat ID را در تنظیمات **Direct** وارد کنید.

### فرمت گزارش

بعد از هر به‌روزرسانی، پیامی شبیه این ارسال می‌شود:

```
🗓 تاریخ: 1404/03/10
⏰ ساعت: 00:05
💰 دلار: 88,000 تومان
📊 خلاصه:
  آپدیت: 42 محصول
  بی‌تغییر: 18 محصول
  مجموع Δ: +12,500,000 تومان
  حالت: اتومات 🔁
────────────────────
📦 نام محصول
قبلی: 10,000,000  →  جدید: 10,500,000
Δ: +500,000  |  5% 🔺
```

---

## Telegram Setup

Two delivery methods are available:

### Method 1: Cloudflare Worker (recommended)

Use this if your server cannot reach `api.telegram.org`.

1. Create a Worker in the [Cloudflare Dashboard](https://dash.cloudflare.com).
2. Deploy the code from `admin/worker-template.js`.
3. Replace `BOT_TOKEN` with your Telegram bot token.
4. Enter the Worker URL and Chat ID in the plugin's Telegram tab.

### Method 2: Direct Bot

If your server can reach the Telegram API directly:

1. Create a bot via [@BotFather](https://t.me/BotFather).
2. Find your channel/group Chat ID.
3. Enter the token and Chat ID under **Direct** mode in settings.

Reports include date, USD rate, summary stats, and per-product before/after prices.

---

## Snapshot و Rollback

### Snapshot چیست؟

قبل از هر به‌روزرسانی (خودکار یا دستی)، پلاگین قیمت **تمام محصولات منتشرشده** را در یک فایل JSON ذخیره می‌کند:

```
wp-content/dpu-snapshots/YYYY-MM-DD.json
```

هر فایل شامل:
- تاریخ و ساعت
- نرخ دلار لحظه snapshot
- لیست محصولات با ID، عنوان و قیمت

### Rollback

از تب **برگشت قیمت** می‌توانید:

- به snapshot یک تاریخ خاص برگردید
- فقط محصولات مشخص (با ID) را بازیابی کنید
- یا همه محصولات snapshot را restore کنید

بعد از rollback، `_dpu_base_price` و `_dpu_upload_dollar` هم به مقادیر snapshot برمی‌گردند.

> **هشدار:** rollback قیمت‌ها را مستقیماً در دیتابیس تغییر می‌دهد. قبل از rollback مهم، یک snapshot دستی بگیرید.

---

## Snapshots & Rollback

### What is a snapshot?

Before each update (scheduled or manual), the plugin saves **all published product prices** to a JSON file:

```
wp-content/dpu-snapshots/YYYY-MM-DD.json
```

Each file contains date/time, USD rate, and a product list (ID, title, price).

### Rollback

From the **Rollback** tab you can:

- Restore to a specific date's snapshot
- Restore only selected products (by ID)
- Restore all products from a snapshot

After rollback, `_dpu_base_price` and `_dpu_upload_dollar` are reset to snapshot values.

> **Warning:** Rollback writes directly to the database. Take a manual snapshot before important rollbacks.

---

## ساختار فایل‌ها

```
price-updater/
├── dollar-price-updater.php    # نقطه ورود پلاگین
├── includes/
│   ├── class-options.php       # تنظیمات و مقادیر پیش‌فرض
│   ├── class-api.php           # دریافت قیمت دلار (API + cache)
│   ├── class-updater.php       # موتور اصلی به‌روزرسانی قیمت
│   ├── class-scheduler.php     # زمان‌بندی WP-Cron
│   ├── class-logger.php        # لاگ + snapshot
│   ├── class-rollback.php      # بازیابی قیمت‌ها
│   └── class-telegram.php      # ارسال پیام تلگرام
└── admin/
    ├── class-admin.php         # پنل مدیریت + AJAX
    └── worker-template.js      # قالب Cloudflare Worker
```

---

## File Structure

```
price-updater/
├── dollar-price-updater.php    # Plugin bootstrap
├── includes/
│   ├── class-options.php       # Settings & defaults
│   ├── class-api.php           # USD price fetch (API + cache)
│   ├── class-updater.php       # Core price update engine
│   ├── class-scheduler.php     # WP-Cron scheduling
│   ├── class-logger.php        # Logging + snapshots
│   ├── class-rollback.php      # Price restore
│   └── class-telegram.php      # Telegram messaging
└── admin/
    ├── class-admin.php         # Admin panel + AJAX
    └── worker-template.js      # Cloudflare Worker template
```

---

## متادیتای محصول

| Meta Key | توضیح |
|----------|--------|
| `_dpu_base_price` | قیمت پایه مرجع |
| `_dpu_upload_dollar` | نرخ دلار زمان ثبت قیمت پایه |
| `_dpu_last_update_time` | زمان آخرین به‌روزرسانی توسط پلاگین |

این فیلدها هنگام **اولین ذخیره محصول** (یا اولین اجرای پلاگین روی آن) به‌صورت خودکار ساخته می‌شوند.

---

## Product Metadata

| Meta Key | Description |
|----------|-------------|
| `_dpu_base_price` | Reference base price |
| `_dpu_upload_dollar` | USD rate when base price was saved |
| `_dpu_last_update_time` | Last update timestamp by the plugin |

These fields are created automatically on **first product save** (or first plugin run on that product).

---

## لاگ و عیب‌یابی

### فایل لاگ

```
wp-content/dpu-log.txt
```

هر خط شامل timestamp و پیام است. خطاهای بحرانی (Fatal Error) حتی با غیرفعال بودن لاگ هم ثبت می‌شوند.

### مشکلات رایج

| مشکل | راه‌حل |
|------|--------|
| قیمت دلار «نامشخص» | API Key را بررسی کنید. از تب ابزارها «تست API» بزنید. |
| Cron اجرا نمی‌شود | WP-Cron را فعال کنید یا cron واقعی سرور تنظیم کنید. |
| Timeout در آپدیت | پلاگین به‌صورت batch از AJAX استفاده می‌کند — صبر کنید تا progress bar تمام شود. |
| تلگرام پیام نمی‌دهد | Worker URL / Bot Token و Chat ID را بررسی کنید. لاگ را ببینید. |
| قیمت تغییر نکرد | اولین اجرا فقط meta می‌سازد. دلار باید از زمان ثبت بالا رفته باشد. |
| قیمت کم نشد | در حالت auto/manual کاهش قیمت عمداً غیرفعال است — از «تنظیم قیمت» استفاده کنید. |

### WP-Cron

پلاگین از `wp_schedule_event` با timezone **Asia/Tehran** استفاده می‌کند. برای reliability بهتر:

```bash
# crontab سرور (هر ۵ دقیقه)
*/5 * * * * wget -q -O - https://yoursite.com/wp-cron.php?doing_wp_cron >/dev/null 2>&1
```

---

## Logging & Troubleshooting

### Log file

```
wp-content/dpu-log.txt
```

Each line has a timestamp and message. Critical fatal errors are logged even when logging is disabled.

### Common issues

| Issue | Fix |
|-------|-----|
| USD shows "unknown" | Check API key. Use "Test API" in Tools tab. |
| Cron not running | Enable WP-Cron or set up a real server cron. |
| Update timeout | The plugin uses AJAX batching — wait for the progress bar to finish. |
| No Telegram messages | Verify Worker URL / Bot Token and Chat ID. Check the log. |
| Price didn't change | First run only creates metadata. USD must have risen since save. |
| Price didn't decrease | Decreases are disabled in auto/manual — use "Price Adjust" instead. |

### WP-Cron

The plugin schedules events in **Asia/Tehran** timezone. For better reliability, trigger `wp-cron.php` from a server cron job (see example above).

---

## نکات مهم

- **قیمت هرگز در حالت خودکار کاهش پیدا نمی‌کند** — فقط با «تنظیم قیمت» می‌توانید کاهش دهید.
- **به‌روزرسانی مستقیم در DB** انجام می‌شود تا hookهای تم/پلاگین‌های دیگر fire نشوند.
- **محصولات بدون قیمت** (regular price ≤ 0) نادیده گرفته می‌شوند.
- **کش دلار** با transient ذخیره می‌شود — از تب ابزارها می‌توانید پاک کنید.
- **نوار ادمین** قیمت لحظه‌ای دلار را نشان می‌دهد (کلیک → تنظیمات).

---

## Important Notes

- **Prices never decrease in auto/manual mode** — use "Price Adjust" to decrease.
- **Updates write directly to the DB** to avoid triggering theme/plugin hooks.
- **Products without a price** (regular price ≤ 0) are skipped.
- **USD cache** is stored as a transient — clear it from the Tools tab.
- **Admin bar** shows the live USD rate (click → settings).

---

## لایسنس

این پروژه توسط mr-noctis توسعه یافته است.

## License

Developed by mr-noctis.
