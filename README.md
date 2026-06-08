# Seo Uptime Monitorr v4 -Deployment & Setup Guide

A lightweight web monitoring system with uptime tracking, SEO auditing, content change detection, and alerting -all controlled from a real-time dashboard. 

---

## 🚀 What's New in v4
 
| Feature | Description |
|---|---|
| Manual Test Button | Run monitoring instantly via secure API (no cron wait) |
| Fully Dynamic Config | All URLs, groups, and thresholds stored in `logs/settings.json` |
| URL Manager UI | Add / edit / delete monitored URLs directly from dashboard |
| Settings Panel | Manage Bale alerts, thresholds, retries from UI |
| SEO + Health Monitoring | Combines uptime, performance, and SEO signals |
| Content Change Tracking | Detects and analyzes page structure changes |
| Indexability Analysis | Detects noindex, canonical issues, and SERP risks |
| RTL / Persian Support | Full RTL layout + Vazirmatn font + language toggle |
| Live Run Logs | Per-URL execution logs in dashboard |
| Toast Notifications | Instant UI feedback for all actions |

---

## 📁 File Structure

```
your-monitor-dir/
├── monitor.php              # Core monitoring engine (cron + API trigger)
├── api.php                  # Dashboard API (auth, config, URL management)
├── dashboard.html          # Web UI dashboard
├── config.php              # Optional server-side secrets override
└── logs/
    ├── settings.json       # Main configuration (URLs, groups, thresholds)
    ├── latest.json         # Latest snapshot for dashboard
    ├── history.json        # Historical per-URL data
    ├── master_log.json     # Full execution history
    ├── snapshots.json      # Content hash tracking
    ├── seo_scores.json     # Daily SEO scoring history
    ├── alert_state.json    # Bale cooldown tracking
    └── .htaccess           # Protect logs from direct access
```

---

## ⚙️ Installation Steps

### 1. Set Authentication Token

Open `api.php` and set:

```php
const DASHBOARD_TOKEN = 'your-secure-random-token';
```

---

### 2. Setup Cron Job (Production)

Run every 5 minutes:

```bash
*/5 * * * * /usr/local/bin/php8.3 /home/youruser/monitor/monitor.php >> /home/youruser/monitor/logs/cron.log 2>&1
```

---

### 3. Protect Logs Directory

Create `logs/.htaccess`:

```apache
Order deny,allow
Deny from all
```

---

### 4. Open Dashboard

```
https://yourdomain.com/monitor/dashboard.html
```

Login using your `DASHBOARD_TOKEN`.

---

### 5. Add URLs

Inside dashboard:

- Add URL
- Set label + group
- Enable checks (SEO / content / vitals / keywords)

Saved instantly to `logs/settings.json`.

---

## 🧪 Manual Test

- Runs full monitoring instantly
- Requires `X-Monitor-Token`
- Returns latest JSON snapshot
- No cron required

---

## 🔐 config.php (Optional)

```php
<?php
return [
    'Bale_bot_token' => 'your-bot-token',
    'Bale_chat_id'   => '-100xxxxxxxxxx',
    'dashboard_token'    => 'override-token',
];
```

Priority:
```
config.php > settings.json > defaults
```

---

## 🌍 RTL / Persian Support

- RTL default layout
- Vazirmatn font
- RTL ↔ LTR toggle button
- Full UTF-8 support
- No JSON encoding issues

---

## 🔄 Upgrade from v3

1. Replace:
   - monitor.php
   - api.php
   - dashboard.html

2. Keep `/logs` folder

3. Re-import URLs (or copy settings.json)

4. Update DASHBOARD_TOKEN

No migration needed.

---

## ⚙️ settings.json Example

```json
{
  "Bale_bot_token": "123:ABC",
  "Bale_chat_id": "-100123",
  "slow_threshold_ms": 3000,
  "ttfb_slow_ms": 600,
  "max_retries": 3,
  "retry_delay_sec": 5,
  "request_timeout": 20,
  "alert_cooldown_min": 60,
  "seo_score_drop_pct": 10,
  "max_history_entries": 2000,
  "url_groups": {
    "Main Site": [
      {
        "url": "https://example.com",
        "label": "Homepage",
        "expected_status": 200,
        "check_seo": true,
        "check_content": true,
        "check_indexability": true,
        "check_vitals": true,
        "check_keywords": true,
        "check_serp_risk": true,
        "check_change_impact": true,
        "keyword_present": "",
        "target_keywords": ["seo", "optimization"]
      }
    ]
  }
}
```

---

## 🧠 Overview

This system provides:

- Uptime monitoring
- Performance tracking
- SEO auditing
- Content change detection
- SERP risk analysis
- Bale alerts
- Historical analytics

---

## ⚠️ Notes

- Do not expose `/logs` publicly
- Use HTTPS in production
- Rotate DASHBOARD_TOKEN regularly
- Keep cron interval ≥ 5 minutes

---

## 🖥️ Demo

### Dashboard Overview
![Dashboard](https://inaser.ir/monitor/docs/screenshots/dashboard.png)

### SEO & Monitoring View
![SEO View](https://inaser.ir/monitor/docs/screenshots/seo.jpg)

### Alert Example (Bale)
![Alert](https://inaser.ir/monitor/docs/screenshots/alert.jpg)

> Screenshots show real-time monitoring, SEO scoring, and alert system in action.png

---

## ⚡ Quick Start (2 Minutes)

```bash
# 1. Clone project
git clone https://github.com/n4ser/webpulse-monitor.git
cd webpulse-monitor

# 2. Set permissions
chmod -R 755 logs

# 3. Open dashboard
http://yourdomain.com/monitor/dashboard.html

# live demo 
https://inaser.ir/monitor/demo/index.html


```

Then:
- Enter your `DASHBOARD_TOKEN`
- Add your first URL
- Click “Manual Test” to verify system

Done.

---

## 📦 Requirements

### PHP Version
- PHP 8.0+ required
- Recommended: PHP 8.3

Check version:
```bash
php -v
```

---

### Required PHP Extensions
- cURL (`php-curl`)
- DOM (`php-xml`)
- JSON (enabled by default)

Check cURL:
```bash
php -m | grep curl
```

---

### Server Requirements
- Writable `logs/` directory
- Cron support (for production mode)
- HTTPS recommended (production)

Fix permissions:
```bash
chmod -R 755 logs
```

---

## ⚠️ Important Notes

- Do NOT expose `/logs` directory publicly
- Keep `config.php` outside version control
- Rotate `DASHBOARD_TOKEN` periodically
- Use HTTPS in production environments

---

## 📄 License

This project is licensed under the MIT License.

```
MIT License

Copyright (c) 2026

Permission is hereby granted, free of charge, to any person obtaining a copy...
(standard MIT license text applies)
```

---

## 🧠 Summary

This system provides:

- Uptime monitoring
- SEO auditing
- Content change detection
- Performance tracking
- SERP risk analysis
- Bale alert automation
- Real-time dashboard analytics


# مانیتورینگ SEO و آپ‌تایم v4 -راهنمای نصب و راه‌اندازی

سیستم سبک مانیتورینگ وب با قابلیت بررسی آپ‌تایم، تحلیل SEO، تشخیص تغییرات محتوا و ارسال هشدارها، همه از طریق یک داشبورد بلادرنگ قابل کنترل است.

---

## 🚀 ویژگی‌های جدید در نسخه 4

| ویژگی | توضیح |
|---|---|
| دکمه تست دستی | اجرای فوری مانیتورینگ از طریق API امن بدون نیاز به cron |
| تنظیمات کاملاً داینامیک | همه URLها، گروه‌ها و آستانه‌ها در `logs/settings.json` |
| مدیریت URL از UI | افزودن / ویرایش / حذف URLها مستقیم از داشبورد |
| پنل تنظیمات | مدیریت هشدارهای Bale، آستانه‌ها و retryها |
| مانیتورینگ SEO و سلامت | ترکیب وضعیت uptime، عملکرد و سیگنال‌های SEO |
| تشخیص تغییر محتوا | شناسایی تغییرات ساختاری صفحات |
| تحلیل ایندکس‌پذیری | تشخیص noindex، مشکلات canonical و ریسک SERP |
| پشتیبانی RTL / فارسی | رابط RTL کامل + فونت Vazirmatn + تغییر زبان |
| لاگ زنده اجراها | نمایش اجرای هر URL در داشبورد |
| نوتیفیکیشن لحظه‌ای | بازخورد فوری برای تمام عملیات |

---

## 📁 ساختار فایل‌ها

```

your-monitor-dir/
├── monitor.php              # موتور اصلی مانیتورینگ (cron + API)
├── api.php                  # API داشبورد (احراز هویت، مدیریت URL)
├── dashboard.html          # رابط کاربری داشبورد
├── config.php              # تنظیمات اختیاری سرور
└── logs/
├── settings.json       # تنظیمات اصلی سیستم
├── latest.json         # آخرین وضعیت
├── history.json        # تاریخچه داده‌ها
├── master_log.json     # لاگ کامل اجراها
├── snapshots.json      # هش محتوایی
├── seo_scores.json     # امتیازهای SEO
├── alert_state.json    # وضعیت هشدارها
└── .htaccess           # محافظت از دسترسی مستقیم

````

---

## ⚙️ مراحل نصب

### 1. تنظیم توکن امنیتی

در فایل `api.php` مقدار زیر را تنظیم کنید:

```php
const DASHBOARD_TOKEN = 'your-secure-random-token';
````

---

### 2. تنظیم کرون جاب (Production)

اجرای هر 5 دقیقه:

```bash
*/5 * * * * /usr/local/bin/php8.3 /home/youruser/monitor/monitor.php >> /home/youruser/monitor/logs/cron.log 2>&1
```

---

### 3. محافظت از پوشه logs

فایل `logs/.htaccess`:

```apache
Order deny,allow
Deny from all
```

---

### 4. باز کردن داشبورد

```
https://yourdomain.com/monitor/dashboard.html
```

ورود با `DASHBOARD_TOKEN`

---

### 5. افزودن URLها

داخل داشبورد:

* افزودن URL
* تعیین label و group
* فعال‌سازی بررسی‌ها (SEO / content / vitals / keywords)

ذخیره‌سازی در `logs/settings.json`

---

## 🧪 تست دستی

* اجرای کامل مانیتورینگ در لحظه
* نیاز به `X-Monitor-Token`
* خروجی JSON آخرین وضعیت
* بدون نیاز به cron

---

## 🔐 config.php (اختیاری)

```php
<?php
return [
    'Bale_bot_token' => 'your-bot-token',
    'Bale_chat_id'   => '-100xxxxxxxxxx',
    'dashboard_token'    => 'override-token',
];
```

اولویت تنظیمات:

```
config.php > settings.json > defaults
```

---

## 🌍 پشتیبانی فارسی / RTL

* رابط RTL پیش‌فرض
* فونت Vazirmatn
* سوییچ RTL ↔ LTR
* پشتیبانی کامل UTF-8
* بدون مشکل encoding در JSON

---

## 🔄 ارتقا از نسخه 3

1. جایگزین کنید:

   * monitor.php
   * api.php
   * dashboard.html

2. پوشه `/logs` را نگه دارید

3. در صورت نیاز settings.json را منتقل کنید

4. DASHBOARD_TOKEN را به‌روزرسانی کنید

---

## ⚙️ نمونه settings.json

```json
{
  "Bale_bot_token": "123:ABC",
  "Bale_chat_id": "-100123",
  "slow_threshold_ms": 3000,
  "ttfb_slow_ms": 600,
  "max_retries": 3,
  "retry_delay_sec": 5,
  "request_timeout": 20,
  "alert_cooldown_min": 60,
  "seo_score_drop_pct": 10,
  "max_history_entries": 2000,
  "url_groups": {
    "Main Site": [
      {
        "url": "https://example.com",
        "label": "Homepage",
        "expected_status": 200,
        "check_seo": true,
        "check_content": true,
        "check_indexability": true,
        "check_vitals": true,
        "check_keywords": true,
        "check_serp_risk": true,
        "check_change_impact": true,
        "keyword_present": "",
        "target_keywords": ["seo", "optimization"]
      }
    ]
  }
}
```

---

## 🧠 توضیح سیستم

این سیستم شامل موارد زیر است:

* مانیتورینگ uptime
* تحلیل عملکرد
* بررسی SEO
* تشخیص تغییرات محتوا
* تحلیل ریسک SERP
* ارسال هشدار به Bale
* داشبورد لحظه‌ای

---

## ⚠️ نکات مهم

* پوشه `/logs` نباید عمومی باشد
* استفاده از HTTPS در محیط production توصیه می‌شود
* توکن داشبورد را دوره‌ای تغییر دهید
* فاصله اجرای cron حداقل 5 دقیقه باشد

---

## 🖥️ نسخه دمو

### نمای داشبورد

![Dashboard](https://inaser.ir/monitor/docs/screenshots/dashboard.png)

### نمای SEO و مانیتورینگ

![SEO View](https://inaser.ir/monitor/docs/screenshots/seo.jpg)

### نمونه هشدار (Bale)

![Alert](https://inaser.ir/monitor/docs/screenshots/alert.jpg)

---

## ⚡ راه‌اندازی سریع (2 دقیقه)

```bash
# کلون پروژه
git clone https://github.com/n4ser/webpulse-monitor.git
cd webpulse-monitor

# تنظیم دسترسی‌ها
chmod -R 755 logs

# باز کردن داشبورد
http://yourdomain.com/monitor/dashboard.html
```

سپس:

* وارد کردن DASHBOARD_TOKEN
* افزودن اولین URL
* اجرای تست دستی

---

## 📦 نیازمندی‌ها

### نسخه PHP

* PHP 8.0 به بالا
* پیشنهاد: PHP 8.3

بررسی نسخه:

```bash
php -v
```

---

### افزونه‌های مورد نیاز PHP

* cURL (`php-curl`)
* DOM (`php-xml`)
* JSON (به‌صورت پیش‌فرض فعال)

بررسی cURL:

```bash
php -m | grep curl
```

---

### نیازمندی‌های سرور

* دسترسی نوشتن در پوشه `logs`
* پشتیبانی cron
* توصیه به استفاده از HTTPS

---

## ⚠️ نکات امنیتی

* از public شدن `/logs` جلوگیری کنید
* فایل `config.php` را در نسخه کنترل قرار ندهید
* توکن داشبورد را دوره‌ای تغییر دهید
* از HTTPS استفاده کنید

---

## 📄 لایسنس

این پروژه تحت لایسنس MIT منتشر شده است.

```
MIT License

Copyright (c) 2026

Permission is hereby granted, free of charge, to any person obtaining a copy...
(متن کامل لایسنس MIT اعمال می‌شود)
```

---

## 🧠 جمع‌بندی

این سیستم فراهم می‌کند:

* مانیتورینگ uptime
* تحلیل SEO
* تشخیص تغییرات محتوا
* بررسی عملکرد
* تحلیل ریسک SERP
* هشدار خودکار Bale
* داشبورد بلادرنگ


