# Part 6 — CAPTURE PROTOCOL

# ⛔ AT THE FIRST FAILURE, CAPTURE BEFORE YOU FIX

**This is the most important page in the pack.**

A staging error was hit on this project once, fixed-or-abandoned without a record, and is now unrecoverable:
`PROJECT-STATE.md:1679` is the only substantive trace — *"a staging error was hit earlier and never debugged.
It is not diagnosed and not written up."* No command, no error text, no stack trace, no environment, no date.

**It has now been parked twice for want of exactly this.** The entire cost of that item is that nobody ran
these eight commands before touching anything. Whatever breaks next, the debugging starts from zero again
unless you capture first.

Fixing is fine. Fixing **before capturing** is what destroys the evidence — the failing state usually cannot
be reproduced once config, cache or `.env` has been touched.

---

## Run all of this, in one file, before changing anything

```bash
exec > >(tee /tmp/careos-failure-$(date +%Y%m%d-%H%M%S).txt) 2>&1

# 1. THE EXACT COMMAND THAT FAILED — verbatim, with full verbosity.
#    Copy it exactly as you ran it. Do not clean it up, do not retype it from memory,
#    do not drop the flags. If it was a browser request, give the full URL and method.
<the exact command> -vvv

# 2. THE FULL, UNEDITED OUTPUT.
#    Do not trim it to "the relevant part" — the relevant part is routinely three lines
#    above where it looks like it starts, and a truncated stack trace has repeatedly cost
#    more time than the original bug.

# 3. ENVIRONMENT
php artisan about
php -v
php -m | sort
php artisan --version

# 4. THE TWO KEYS THAT CHANGE EVERY OTHER ANSWER
grep -E '^(APP_ENV|APP_DEBUG)=' .env

# 5. EXTENSIONS — the parked error's leading candidate lives here
php -m | grep -qx redis && echo "phpredis: PRESENT" || echo "phpredis: ABSENT"
grep '^REDIS_CLIENT' .env
php-fpm8.2 -i | grep '^Loaded Configuration File'     # the FPM ini is NOT the CLI ini

# 6. THE ENV KEYS THAT MATTER — secrets redacted, values kept
grep -E '^(APP_ENV|APP_DEBUG|APP_URL|DB_CONNECTION|DB_HOST|DB_PORT|DB_DATABASE|DB_USERNAME|QUEUE_CONNECTION|CACHE_STORE|SESSION_DRIVER|SESSION_SECURE_COOKIE|REDIS_CLIENT|REDIS_HOST|REDIS_PORT|MAIL_MAILER|MAIL_HOST|MAIL_PORT|MAIL_FROM_ADDRESS|LOG_CHANNEL|LOG_STACK|LOG_LEVEL|FILESYSTEM_DISK)=' .env \
  | sed -E 's/(PASSWORD|SECRET|KEY|TOKEN)=.*/\1=***REDACTED***/'

# 7. THE APPLICATION LOG — last 50 lines.
#    Use the GLOB, not a fixed filename. With LOG_STACK=daily (what
#    docs/DEPLOY-ENV.production.template:40 ships) there is no laravel.log at all — the file is
#    laravel-YYYY-MM-DD.log. DEPLOY-CHECKLIST.md:370's `tail storage/logs/laravel.log` reads a
#    file that does not exist on a template-configured box.
tail -n 50 $(ls -t storage/logs/laravel*.log 2>/dev/null | head -1)

# 8. THE NGINX ERROR LOG — last 50 lines.
#    A 502/504 leaves NOTHING in the Laravel log, because PHP never got far enough to write one.
#    This is the file that tells you so.
sudo tail -n 50 /var/log/nginx/careos-error.log
```

### Add these when the failure is queue-, schedule- or DB-shaped

```bash
# queue / workers
php artisan horizon:status
sudo systemctl status careos-horizon --no-pager
sudo journalctl -u careos-horizon -n 100 --no-pager
redis-cli ping
redis-cli -n 0 llen queues:default

# scheduler
sudo crontab -u www-data -l
php artisan schedule:list
sudo grep CRON /var/log/syslog | tail -20

# database
php artisan migrate:status | tail -20
mysql -u careos -p -e "SELECT VERSION(), @@global.time_zone\G"
```

---

## Then write it up **before** you fix it

Record, in `PROJECT-STATE.md` or a gate note:

1. What you ran and what you expected.
2. What happened instead — the verbatim first error line.
3. The environment differences you can see between this box and a working one.
4. Your hypothesis, and **how you tested it** — not just what you changed.
5. What actually fixed it.

**Point 5 alone is not a write-up.** "Reinstalled the extension and it worked" is how the first staging error
became permanently unrecoverable: it records the action and loses the diagnosis, so the second occurrence
starts from nothing.

---

## Known candidates — check these before assuming something novel

| Symptom | Likely cause | Confirm with |
|---|---|---|
| Hard fatal `Class "Redis" not found` on the **first request** | `REDIS_CLIENT=phpredis` (the shipped template default) with `php8.2-redis` absent. Session/cache/queue are all on Redis, so it fatals immediately. **The leading candidate for the parked error** — dev and CI both run predis, so this cannot surface locally. | Part 1 §1.4 |
| Every authenticated page 500s; login page unstyled | `public/build/manifest.json` missing — the Vite build never ran on the server (`.gitignore:5-6`). | Part 3 §4 |
| `/nurse-pwa/` 404s | nginx serves only `index index.php`; the PWA is a static SPA with no Laravel route. | Part 2 nginx `[FIX]` |
| Uploads fail with **"A file is required"** on a 10 MB file | PHP's stock `upload_max_filesize=2M`. Nginx accepted it; PHP discarded it. The message names the wrong cause. | Part 1 §1.2 |
| Horizon healthy, nothing processes | `QUEUE_CONNECTION=database` (the code default). | Part 5 §6 |
| Email silently never arrives, **no log entry either** | `MAIL_MAILER=log` plus `LOG_LEVEL=warning` — the transport logs at debug, so the record is discarded. | Part 5 §7 |
| Cache/log `Permission denied` from a hand-run artisan command | `storage/` and `bootstrap/cache` are `www-data:www-data` while you are running as your SSH user. | Part 3 §0 |
| Money `CHECK` constraints not enforced | MySQL older than 8.0.16 — they migrate fine and are inert. | Part 1 §1.5 |
