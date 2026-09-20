#!/usr/bin/env bash
# DESTINATION: /var/www/careos/docs/deploy/release.sh  (it ships with the repo — run it in place)
#   sudo -u www-data bash docs/deploy/release.sh
#
# A deploy from a clean checkout onward. Safe to re-run: every step is idempotent, and the
# script stops at the first failure rather than continuing into a half-deployed state.
#
# ---------------------------------------------------------------------------------------------
# WHAT THIS SCRIPT DELIBERATELY DOES NOT DO
# ---------------------------------------------------------------------------------------------
#   * It does NOT seed.            `db:seed --force` is a FIRST-INSTALL step (Part 4), not a
#                                  release step. It is idempotent, but running it on every
#                                  deploy hides a failure to run it on the first one.
#   * It does NOT create tenants, branches or admins. Those are deliberate, one-off,
#                                  customer-specific acts with irreversible flags — `tenant:create`
#                                  takes an IMMUTABLE --region. They belong to a human. Part 4.
#   * It does NOT run demo seeders. TWO independent protections, and this omission is the second:
#                                  the first is code. QA-FIX.9b (4e610b0) gave every demo seeder a
#                                  hard guard — database/seeders/Concerns/RefusesOutsideDevelopment.php:40
#                                  allows ONLY ['local','testing'] and throws a RuntimeException
#                                  anywhere else, so a demo seeder invoked by hand on a production
#                                  host refuses by its own guard. This script simply never names
#                                  them, so no automation can invoke them by accident either.
#                                  Belt and braces, on purpose.
#                                  CAVEAT, and it is a real one: that guard covers the four Demo*
#                                  seeders. database/seeders/SimulatedBillingMonthSeeder.php has
#                                  NO guard and still creates a full fake tenant. Never run any
#                                  `db:seed --class=` on a customer host.
#   * It does NOT touch .env.      Config changes are a human decision, and a bad one is a fatal.
#   * It does NOT roll back.       There is no releases/-symlink scheme in this repo; a rollback
#                                  is `git checkout <previous-sha>` then re-run this script.
#                                  Migrations are forward-only — that is a real limit, stated
#                                  rather than papered over.
# ---------------------------------------------------------------------------------------------

set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/careos}"
PHP="${PHP:-/usr/bin/php}"
FPM_SERVICE="${FPM_SERVICE:-php8.2-fpm}"

cd "$APP_DIR"

say() { printf '\n\033[1m==> %s\033[0m\n' "$1"; }

# --- 0. Preconditions -------------------------------------------------------------------------
# Running as the wrong user is the most common cause of a "works, then 500s" deploy: artisan
# writes bootstrap/cache/config.php and storage/logs as whoever invokes it, and PHP-FPM then
# cannot read or write them. The runbook chowns both trees to www-data:www-data 775
# (DEPLOY-RUNBOOK.md:269-270) and then tells you to run artisan as your own SSH user
# (DEPLOY-RUNBOOK.md:296-299) — which leaves that user with only "other" permissions. This check
# is here because that combination is documented and broken.
if [ "$(id -un)" != "www-data" ]; then
  echo "This script must run as www-data, or the cache files it writes will be unreadable by PHP-FPM."
  echo "Run:  sudo -u www-data bash docs/deploy/release.sh"
  exit 1
fi

say "Release starting in $APP_DIR as $(id -un)"
git rev-parse --short HEAD

# --- 1. Maintenance mode ----------------------------------------------------------------------
# Best-effort: on a first deploy the app may not be bootable yet, and that must not abort the run.
$PHP artisan down --retry=15 2>/dev/null || echo "(app not bootable yet — skipping maintenance mode)"
trap '$PHP artisan up 2>/dev/null || true' EXIT

# --- 2. Code ----------------------------------------------------------------------------------
say "Pulling code"
git pull --ff-only

# --- 3. PHP dependencies ----------------------------------------------------------------------
# --no-dev omits Pest, Pint and PHPStan: you cannot run `composer check` on a box deployed this
# way, which is intentional. It is also why ext-gd is not needed in production — its only use in
# this repo is UploadedFile::fake()->image() inside the test suite.
say "composer install"
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

# --- 4. Frontend ------------------------------------------------------------------------------
# BOTH builds are mandatory and they produce DISJOINT outputs. .gitignore:5-6 ignores
# /public/build and /public/nurse-pwa, so NEITHER arrives with git pull — they must be built here
# on every release.
#   npm run build      -> public/build        the main Inertia/Vue app
#   npm run build:pwa  -> public/nurse-pwa    the offline Nurse PWA + service worker
# The main build never contains the PWA: vite.config.js declares only the app entries, and the
# PWA has its own config (nurse-pwa/vite.config.ts:30 outDir '../public/nurse-pwa').
say "npm ci && build && build:pwa"
npm ci
npm run build
npm run build:pwa

# Prove both landed. A missing manifest 500s every authenticated page on a blank screen, which is
# a miserable thing to diagnose from the browser.
test -f public/build/manifest.json || { echo "FATAL: public/build/manifest.json missing after build"; exit 1; }
test -f public/nurse-pwa/index.html || { echo "FATAL: public/nurse-pwa/index.html missing after build:pwa"; exit 1; }
test -f public/nurse-pwa/sw.js      || { echo "FATAL: public/nurse-pwa/sw.js missing after build:pwa"; exit 1; }
echo "build artefacts present"

# --- 5. Database ------------------------------------------------------------------------------
# --force because production is non-interactive. Forward-only; never migrate:fresh here.
say "Migrating"
$PHP artisan migrate --force
$PHP artisan migrate:status | grep -i pending && { echo "FATAL: pending migrations remain"; exit 1; } || true

# --- 6. Caches --------------------------------------------------------------------------------
# config:cache is what makes env() outside config/ dangerous — this repo has none, so it is safe
# (verified in DEPLOY-READINESS-CHECK.md:63). Clear before caching so a stale cache cannot
# survive a failed build.
say "Rebuilding caches"
$PHP artisan optimize:clear
$PHP artisan config:cache
$PHP artisan route:cache
$PHP artisan view:cache
$PHP artisan event:cache

# --- 7. Permissions ---------------------------------------------------------------------------
# Only these two trees need to be writable. setgid on directories keeps the group correct for
# files created later by either the web worker or a hand-run artisan command.
say "Permissions"
chmod -R u+rwX,g+rwX storage bootstrap/cache
find storage bootstrap/cache -type d -exec chmod g+s {} +

# --- 8. Restart the workers -------------------------------------------------------------------
# horizon:terminate stops the master gracefully AFTER in-flight jobs finish; systemd (or
# Supervisor) then restarts it with the new code. Workers are long-lived PHP processes and hold
# the OLD code in memory until they are cycled — skip this and your deploy is only half live.
say "Cycling Horizon"
$PHP artisan horizon:terminate || echo "(Horizon was not running)"

# Required because careos-php.ini sets opcache.validate_timestamps=0: without a reload, FPM keeps
# serving the previous bytecode and the deploy appears to have done nothing.
say "Reloading PHP-FPM"
sudo systemctl reload "$FPM_SERVICE" 2>/dev/null \
  || echo "(could not reload $FPM_SERVICE — do it manually, or new code will not be served)"

# --- 9. Done ----------------------------------------------------------------------------------
$PHP artisan up
trap - EXIT

say "Release complete"
$PHP artisan about --only=environment || true
echo
echo "Now run the smoke test: docs/deploy/05-smoke-test.md"
