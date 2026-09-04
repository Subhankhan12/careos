<?php

namespace App\Services;

use Modules\Platform\Services\SettingsService;
use Modules\Platform\Services\TenantContext;

/**
 * THE DISPLAY BOUNDARY for the tenant's timezone (QA-FIX.1a, D-192).
 *
 * Storage is UTC from every path — web, CLI, queue and scheduler — so a datetime column
 * holds exactly one time base. Showing a stored instant in the practice's zone is a
 * separate, PRESENTATION concern, and this is the one place that resolves which zone that
 * is.
 *
 * It deliberately does NOT mutate anything. The predecessor of this class was a
 * `date_default_timezone_set()` call in `ApplyTenantLocaleTimezone`, which changed the
 * process-wide default and therefore silently changed what Eloquent WROTE — storing the
 * practice's wall clock in a UTC column. A resolver returns a value; it cannot have that
 * effect.
 *
 * Callers convert at the point of rendering (today: the client, via the `timezone` prop
 * shared by `HandleInertiaRequests`). A server-side surface that needs to render a stored
 * instant locally should read this and convert explicitly — never by changing the default.
 */
class DisplayTimezone
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly SettingsService $settings,
    ) {}

    /**
     * The IANA zone a tenant's staff should SEE instants rendered in.
     *
     * Falls back to the platform default (`config('app.timezone')`, UTC) when there is no
     * tenant context (guests, platform-level work) or the tenant never configured one. An
     * unrecognised identifier falls back too rather than propagating a value that would
     * throw at conversion time.
     */
    public function forCurrentTenant(): string
    {
        $fallback = (string) config('app.timezone');

        if (! $this->tenants->has()) {
            return $fallback;
        }

        $timezone = (string) $this->settings->get('timezone', $fallback);

        if ($timezone === '' || ! in_array($timezone, timezone_identifiers_list(), true)) {
            return $fallback;
        }

        return $timezone;
    }
}
