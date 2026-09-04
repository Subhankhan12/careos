<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Platform\Services\SettingsService;
use Modules\Platform\Services\TenantContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the tenant's configured LOCALE for the request, once tenant context is
 * established. Runs AFTER IdentifyTenantFromUser so the tenant-scoped SettingsService
 * can be read; self-skips for guests (no tenant context).
 *
 * TIMEZONE — DELIBERATELY NOT SET HERE (QA-FIX.1a, D-192).
 *
 * This middleware used to call `date_default_timezone_set($tenantZone)`, and its own
 * docblock claimed that "Eloquent keeps serialising timestamps in UTC (stored data is
 * unchanged)". THAT CLAIM WAS FALSE, and it is how the defect survived review. `now()`
 * returns a Carbon in PHP's default zone, and Eloquent serialises that WALL CLOCK
 * verbatim — so every datetime written during a web request was stored as the practice's
 * local time in a column every other consumer reads as UTC. Rows written by CLI, queue
 * workers and the scheduler stayed true UTC, so one column held two time bases (measured:
 * web writes +2h ahead on a Europe/Zurich tenant, including the append-only, hash-chained
 * `audit_events.occurred_at`). It also re-labelled values on READ — see the note in
 * `DayBoardController::appointmentSummary()` (the `checked_in_at` block), which had to work
 * around exactly this by reading the raw column and parsing it as UTC.
 *
 * The rule now: STORAGE IS UTC FROM EVERY PATH (web, CLI, queue, scheduler), and
 * tenant-local rendering is an explicit DISPLAY concern. The tenant's zone is resolved at
 * the presentation boundary instead — `HandleInertiaRequests` shares it to the client as
 * the `timezone` prop — so nothing mutates the process-wide default.
 *
 * Do not reintroduce a process-wide timezone mutation here. If a surface needs
 * tenant-local rendering, convert at that surface from the shared zone.
 */
class ApplyTenantLocaleTimezone
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly SettingsService $settings,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->tenants->has()) {
            $locale = (string) $this->settings->get('locale', (string) config('app.locale'));

            if ($locale !== '') {
                app()->setLocale($locale);
            }
        }

        return $next($request);
    }
}
