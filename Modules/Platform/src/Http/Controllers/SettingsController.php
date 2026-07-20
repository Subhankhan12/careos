<?php

namespace Modules\Platform\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\User;
use Modules\Platform\Services\SettingsService;
use Modules\Platform\Services\TenantContext;

/**
 * Tenant practice-settings admin (admin.manage). Presentation over existing backends:
 * billing currency + invoice identity persist through {@see SettingsService}; the
 * editable practice PROFILE writes to tenant columns (name/contact/address) with
 * locale + timezone via SettingsService (all audited in the app layer). slug/region/
 * status/plan stay read-only — slug is the public booking key, region is immutable,
 * status/plan are platform/billing-controlled. Branch management lives on its own page.
 */
class SettingsController
{
    /** Settlement currencies the practice may pick — validation only; the value is a display label. */
    private const CURRENCIES = ['CHF', 'EUR', 'USD', 'GBP'];

    /** Interface locales offered in the picker (Swiss/EU context). */
    private const LOCALES = ['en', 'de', 'fr', 'it'];

    /** A curated timezone shortlist for the picker; any valid IANA id also validates. */
    private const TIMEZONES = [
        'UTC', 'Europe/Zurich', 'Europe/Berlin', 'Europe/Vienna', 'Europe/Paris',
        'Europe/London', 'Europe/Rome', 'Europe/Madrid', 'America/New_York', 'America/Los_Angeles',
    ];

    public function index(SettingsService $settings, TenantContext $tenants): Response
    {
        Gate::authorize('admin.manage');

        $tenant = $tenants->current();

        $timezone = (string) $settings->get('timezone', 'UTC');
        $timezoneOptions = array_values(array_unique(array_merge(self::TIMEZONES, [$timezone])));

        return Inertia::render('Admin/Settings', [
            // Editable practice profile — persisted to tenant columns + settings.
            'profile' => [
                'name' => (string) ($tenant->name ?? ''),
                'contact_email' => (string) ($tenant->contact_email ?? ''),
                'contact_phone' => (string) ($tenant->contact_phone ?? ''),
                'address_line1' => (string) ($tenant->address_line1 ?? ''),
                'address_line2' => (string) ($tenant->address_line2 ?? ''),
                'city' => (string) ($tenant->city ?? ''),
                'postal_code' => (string) ($tenant->postal_code ?? ''),
                'country' => (string) ($tenant->country ?? ''),
                'locale' => (string) $settings->get('locale', 'en'),
                'timezone' => $timezone,
            ],
            // Read-only identity/platform fields (no write path by design).
            'identity' => [
                'slug' => $tenant?->slug,
                'region' => $tenant?->region,
                'status' => $tenant?->status,
                'plan' => $tenant?->plan?->name,
            ],
            'locales' => self::LOCALES,
            'timezones' => $timezoneOptions,
            // Editable — persisted through the existing SettingsService (tenant-scoped).
            'billing' => [
                'currency' => (string) $settings->get('currency', 'EUR'),
                'seller_name' => (string) $settings->get('billing.seller_name', ''),
                'seller_vat_id' => (string) $settings->get('billing.seller_vat_id', ''),
            ],
            'currencies' => self::CURRENCIES,
            // Read-only branch summary; management is on the Branches page.
            'branches' => Branch::query()->orderBy('name')->get()->map(fn (Branch $branch): array => [
                'id' => $branch->id,
                'name' => $branch->name,
                'code' => $branch->code,
                'city' => $branch->city,
                'timezone' => $branch->timezone,
                'active' => $branch->active,
            ])->all(),
            'rolesUrl' => route('admin.roles.index'),
            'branchesUrl' => route('admin.branches.index'),
            'updateUrl' => route('settings.update'),
            'profileUpdateUrl' => route('settings.profile.update'),
        ]);
    }

    public function update(Request $request, SettingsService $settings): RedirectResponse
    {
        Gate::authorize('admin.manage');
        abort_unless($request->user() instanceof User, 403);

        $data = $request->validate([
            'currency' => ['required', 'string', 'in:'.implode(',', self::CURRENCIES)],
            'seller_name' => ['nullable', 'string', 'max:160'],
            'seller_vat_id' => ['nullable', 'string', 'max:60'],
        ]);

        // Every write goes through the EXISTING SettingsService — no new storage, no
        // money/domain logic; the value is only a label the billing layer already reads.
        $settings->set('currency', $data['currency']);
        $settings->set('billing.seller_name', (string) ($data['seller_name'] ?? ''));
        $settings->set('billing.seller_vat_id', (string) ($data['seller_vat_id'] ?? ''));

        return redirect()->route('settings.index')->with('status', 'saved');
    }

    public function updateProfile(Request $request, SettingsService $settings, TenantContext $tenants): RedirectResponse
    {
        Gate::authorize('admin.manage');
        abort_unless($request->user() instanceof User, 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'contact_email' => ['nullable', 'email', 'max:160'],
            'contact_phone' => ['nullable', 'string', 'max:60'],
            'address_line1' => ['nullable', 'string', 'max:160'],
            'address_line2' => ['nullable', 'string', 'max:160'],
            'city' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'size:2'],
            'locale' => ['required', 'string', 'in:'.implode(',', self::LOCALES)],
            'timezone' => ['required', 'string', 'timezone'],
        ]);

        $tenant = $tenants->current();
        abort_unless($tenant !== null, 403);

        // Profile → tenant columns (region/slug/status/plan deliberately not touched).
        $tenant->update([
            'name' => $data['name'],
            'contact_email' => $data['contact_email'] ?? null,
            'contact_phone' => $data['contact_phone'] ?? null,
            'address_line1' => $data['address_line1'] ?? null,
            'address_line2' => $data['address_line2'] ?? null,
            'city' => $data['city'] ?? null,
            'postal_code' => $data['postal_code'] ?? null,
            'country' => $data['country'] !== null ? strtoupper($data['country']) : null,
        ]);

        // Locale + timezone → SettingsService (applied per request by ApplyTenantLocaleTimezone).
        $settings->set('locale', $data['locale']);
        $settings->set('timezone', $data['timezone']);

        return redirect()->route('settings.index')->with('status', 'profileSaved');
    }
}
