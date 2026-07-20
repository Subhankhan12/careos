<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Platform\Services\SettingsService;
use Modules\Platform\Services\TenantContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the tenant's configured locale + timezone for the request, once tenant
 * context is established. Runs AFTER IdentifyTenantFromUser so the tenant-scoped
 * SettingsService can be read; self-skips for guests (no tenant context).
 *
 * Timezone: `date_default_timezone_set()` makes server-side `now()`/date() operate in
 * the practice's zone — it does NOT touch `config('app.timezone')`, so Eloquent keeps
 * serialising timestamps in UTC (stored data is unchanged). A tenant that never set a
 * value keeps the platform defaults, so existing behaviour is untouched. (Full per-widget
 * datetime→tz display conversion remains a follow-up; the M-2 date-only discipline already
 * renders dates in the viewer's local zone.)
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
            $timezone = (string) $this->settings->get('timezone', (string) config('app.timezone'));

            if ($locale !== '') {
                app()->setLocale($locale);
            }

            if ($timezone !== '' && in_array($timezone, timezone_identifiers_list(), true)) {
                date_default_timezone_set($timezone);
            }
        }

        return $next($request);
    }
}
