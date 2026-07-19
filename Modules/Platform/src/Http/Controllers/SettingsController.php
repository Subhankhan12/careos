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
 * Tenant practice-settings admin (admin.manage). PRESENTATION over the existing
 * SettingsService: reads via {@see SettingsService::get()}, writes via ::set() — no
 * new settings storage is invented. Only settings that ACTUALLY round-trip through
 * the service AND have a runtime consumer are editable: the settlement `currency`
 * (read by landing/reporting/billing) and the invoice-issuer identity the PDF
 * renderer reads (`billing.seller_name` / `billing.seller_vat_id`). The tenant
 * profile and branches are shown READ-ONLY — they have no write backend yet, so
 * they are surfaced honestly rather than faked with a form.
 */
class SettingsController
{
    /** Settlement currencies the practice may pick — validation only; the value is a display label. */
    private const CURRENCIES = ['CHF', 'EUR', 'USD', 'GBP'];

    public function index(SettingsService $settings, TenantContext $tenants): Response
    {
        Gate::authorize('admin.manage');

        $tenant = $tenants->current();

        return Inertia::render('Admin/Settings', [
            // Read-only practice profile (real data; name/region/plan have no write path yet — a gap).
            'profile' => [
                'name' => $tenant?->name,
                'slug' => $tenant?->slug,
                'region' => $tenant?->region,
                'status' => $tenant?->status,
                'plan' => $tenant?->plan?->name,
            ],
            // Editable — persisted through the existing SettingsService (tenant-scoped).
            'billing' => [
                'currency' => (string) $settings->get('currency', 'EUR'),
                'seller_name' => (string) $settings->get('billing.seller_name', ''),
                'seller_vat_id' => (string) $settings->get('billing.seller_vat_id', ''),
            ],
            'currencies' => self::CURRENCIES,
            // Read-only branch list — the Branch model has no create/edit backend yet (a gap).
            'branches' => Branch::query()->orderBy('name')->get()->map(fn (Branch $branch): array => [
                'id' => $branch->id,
                'name' => $branch->name,
                'code' => $branch->code,
                'city' => $branch->city,
                'country' => $branch->country,
                'timezone' => $branch->timezone,
                'active' => $branch->active,
            ])->all(),
            'rolesUrl' => route('admin.roles.index'),
            'updateUrl' => route('settings.update'),
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
}
