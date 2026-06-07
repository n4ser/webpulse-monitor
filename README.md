# URL Monitor v4 — Deployment & Upgrade Guide

## What's New in v4

| Feature | Details |
|---|---|
| **Manual Test button** | Triggers `monitor.php` via secure AJAX — no cron wait |
| **Zero hardcoded config** | Every URL, group, threshold lives in `logs/settings.json` — editable via dashboard |
| **URL Manager UI** | Add / edit / delete URLs and groups live from the browser |
| **Settings panel** | Telegram credentials, thresholds, retries — all editable in-dashboard |
| **RTL / Persian** | Full Vazirmatn font, RTL layout, ⇄ toggle button switches LTR/RTL |
| **Run log viewer** | Manual test shows per-URL output in a log panel |
| **Toast notifications** | Non-blocking feedback for all actions |

---

## File Structure

```
your-monitor-dir/
├── monitor.php        ← Cron script + HTTP-triggered endpoint
├── api.php            ← Dashboard API (auth, data, settings, URL mgmt)
├── dashboard.html     ← Web dashboard
├── config.php         ← (optional) server-side overrides for secrets
└── logs/
    ├── settings.json       ← all config (URL groups, thresholds, Telegram)
    ├── latest.json         ← fast snapshot for dashboard
    ├── history.json        ← per-URL historical entries
    ├── master_log.json     ← full run history
    ├── snapshots.json      ← content-change hashes
    ├── seo_scores.json     ← daily SEO scores
    ├── alert_state.json    ← Telegram cooldown state
    └── .htaccess           ← REQUIRED — deny direct log access
```

---

## Step 1 — Only one thing to hardcode

Open **`api.php`** and set:

```php
const DASHBOARD_TOKEN = 'my-long-random-secret-token';
```

That's the **only hardcoded value** in the entire system.

---

## Step 2 — Set your PHP version in cPanel

Check your available PHP path:
```bash
which php        # auto-detect
/usr/local/bin/php8.3 -v   # test PHP 8.3 directly
```

Cron Job command:
```
*/5 * * * *  /usr/local/bin/php8.3 /home/youruser/monitor/monitor.php >> /home/youruser/monitor/logs/cron.log 2>&1
```

| PHP Version | Binary path |
|---|---|
| PHP 8.3 (recommended) | `/usr/local/bin/php8.3` |
| PHP 8.2 | `/usr/local/bin/php8.2` |
| PHP 8.1 | `/usr/local/bin/php8.1` |
| PHP 8.0 | `/usr/local/bin/php8.0` |
| Auto | `/usr/bin/php` |

---

## Step 3 — Protect logs directory

Create `logs/.htaccess`:

```apache
Order deny,allow
Deny from all
```

Or via cPanel → File Manager → create the file.

---

## Step 4 — Open Dashboard

1. Visit `https://yoursite.com/monitor/dashboard.html`
2. Enter your `DASHBOARD_TOKEN`
3. Token is saved in localStorage — no re-login needed

---

## Step 5 — Add URLs (no PHP editing required)

Click **"+ افزودن URL"** in the dashboard header:
- Enter URL, label, group name
- Toggle which checks to enable
- Click save → immediately live

All URL configs save to `logs/settings.json` and are picked up by the next cron run or Manual Test.

---

## Manual Test Security

The **"▶ تست دستی"** button:

1. Calls `monitor.php` with your `DASHBOARD_TOKEN` in the `X-Monitor-Token` header
2. `monitor.php` validates the token before running anything
3. Returns the fresh `latest.json` payload directly — dashboard updates without page reload
4. No unauthenticated access can trigger a run

---

## config.php (optional server-side overrides)

For extra security, keep secrets out of `settings.json` (which is in the web root):

```php
<?php
// config.php — never commit this to version control
return [
    'telegram_bot_token' => 'your-actual-bot-token',
    'telegram_chat_id'   => '-100xxxxxxxxxx',
    'dashboard_token'    => 'your-actual-dashboard-token',  // overrides api.php constant
];
```

`config.php` overrides `settings.json` which overrides built-in defaults.

---

## Persian / RTL Support

- Dashboard loads in **RTL mode** by default (`<html dir="rtl">`)
- Uses **Vazirmatn** font — best Persian web font, loaded from Google Fonts
- The **⇄** button in the header toggles between RTL (Persian) and LTR (English)
- All JSON logs use `JSON_UNESCAPED_UNICODE` — Persian text stores and displays without corruption
- Group names, labels, and keywords can be in Persian with full UTF-8 support

---

## Upgrading from v3

1. Replace all three files (`monitor.php`, `api.php`, `dashboard.html`)
2. Existing `logs/` data is fully compatible — no migration needed
3. Your existing URL groups in the old `$URL_GROUPS` PHP array need to be re-entered via the dashboard UI (one-time), or copy-pasted into `logs/settings.json` manually under the `url_groups` key
4. Change `DASHBOARD_TOKEN` in the new `api.php`

---

## settings.json structure (for manual editing)

```json
{
  "telegram_bot_token": "123:ABC",
  "telegram_chat_id": "-100123",
  "slow_threshold_ms": 3000,
  "ttfb_slow_ms": 600,
  "max_retries": 3,
  "retry_delay_sec": 5,
  "request_timeout": 20,
  "alert_cooldown_min": 60,
  "seo_score_drop_pct": 10,
  "max_history_entries": 2000,
  "url_groups": {
    "سایت اصلی": [
      {
        "url": "https://example.com",
        "label": "صفحه اصلی",
        "expected_status": 200,
        "check_seo": true,
        "check_content": true,
        "check_indexability": true,
        "check_vitals": true,
        "check_keywords": true,
        "check_serp_risk": true,
        "check_change_impact": true,
        "keyword_present": "",
        "target_keywords": ["سئو", "بهینه‌سازی"]
      }
    ]
  }
}
```
