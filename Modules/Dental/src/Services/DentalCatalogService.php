<?php

namespace Modules\Dental\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Audit\Services\AuditService;
use Modules\Billing\Models\TariffCatalog;
use Modules\Billing\Models\TariffItem;
use Modules\Dental\Exceptions\DentalException;
use Modules\Dental\Models\DentalProcedure;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

/**
 * The dental fee schedule — the tenant's own procedure catalog, authored over the EXISTING
 * Billing tariff engine (NO new pricing logic). A dental procedure IS a `TariffItem` in the
 * tenant's dedicated dental `TariffCatalog` (key 'dental'); the tariff item holds the
 * code / name / FEE / VAT, and a thin {@see DentalProcedure} overlay adds `tooth_scoped`.
 * Charging resolves + snapshots the fee through the tested ChargeCaptureService — this
 * service only AUTHORS the catalog (data entry: names + fees the dentist sets), it computes
 * no charge, no VAT, no line total.
 *
 * NO licensed code set (ADA CDT / Swiss SSO point values) is bundled — the catalog is
 * tenant-authored; {@see seedStarter()} lays down a small GENERIC editable template with
 * placeholder fees the dentist changes. Gated on `billing.manage` (literally "manage
 * billing tariffs and billable items"), tenant-scoped, audited.
 */
class DentalCatalogService
{
    public const CATALOG_KEY = 'dental';

    /**
     * A GENERIC general-dentist starter template — plain names, the tenant's own codes
     * (NOT CDT), placeholder fees the dentist edits. Not a licensed catalog.
     *
     * @var list<array{code: string, name: string, fee: int, tooth_scoped: bool}>
     */
    public const STARTER = [
        ['code' => 'D-EXAM', 'name' => 'Examination', 'fee' => 5000, 'tooth_scoped' => false],
        ['code' => 'D-PROPHY', 'name' => 'Cleaning / prophylaxis', 'fee' => 10000, 'tooth_scoped' => false],
        ['code' => 'D-XRAY', 'name' => 'Radiograph', 'fee' => 3000, 'tooth_scoped' => false],
        ['code' => 'D-RESTOR', 'name' => 'Restoration (filling)', 'fee' => 15000, 'tooth_scoped' => true],
        ['code' => 'D-CROWN', 'name' => 'Crown', 'fee' => 90000, 'tooth_scoped' => true],
        ['code' => 'D-EXTRACT', 'name' => 'Extraction', 'fee' => 18000, 'tooth_scoped' => true],
        ['code' => 'D-RCT', 'name' => 'Root canal (simple)', 'fee' => 60000, 'tooth_scoped' => true],
    ];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuditService $audit,
    ) {}

    /**
     * Get-or-create the tenant's dental tariff catalog (the existing effective-dated Billing
     * catalog; valid from well in the past + open-ended so any service date resolves).
     */
    public function catalog(): TariffCatalog
    {
        return TariffCatalog::query()->firstOrCreate(
            ['key' => self::CATALOG_KEY, 'version' => 1],
            [
                'name' => 'Dental fee schedule',
                'valid_from' => Carbon::create(2020, 1, 1)->toDateString(),
                'status' => TariffCatalog::STATUS_ACTIVE,
            ],
        );
    }

    /**
     * Seed the generic starter template for the current tenant. Idempotent by code.
     */
    public function seedStarter(User $actor): int
    {
        $this->authorize($actor);
        $catalog = $this->catalog();
        $created = 0;

        foreach (self::STARTER as $starter) {
            $item = TariffItem::query()->firstOrCreate(
                ['tariff_catalog_id' => $catalog->id, 'code' => $starter['code']],
                [
                    'description' => $starter['name'],
                    'unit_price_minor' => $starter['fee'],
                    'vat_rate_bp' => 0,
                    'unit' => 'procedure',
                    'requires_service_documentation' => false,
                    'active' => true,
                ],
            );

            $procedure = DentalProcedure::query()->firstOrCreate(
                ['tariff_item_id' => $item->id],
                ['tooth_scoped' => $starter['tooth_scoped']],
            );

            if ($procedure->wasRecentlyCreated) {
                $created++;
                $this->auditCatalog('dental.procedure.created', $procedure, $item, $actor);
            }
        }

        return $created;
    }

    /**
     * Author a new procedure: a tariff item (its fee/name) + the dental overlay. The fee is
     * the value the dentist entered — no computation.
     */
    public function create(User $actor, string $code, string $name, int $feeMinor, int $vatBp, bool $toothScoped): DentalProcedure
    {
        $this->authorize($actor);
        $this->assertInputs($code, $name, $feeMinor, $vatBp);
        $catalog = $this->catalog();

        return DB::transaction(function () use ($catalog, $code, $name, $feeMinor, $vatBp, $toothScoped, $actor): DentalProcedure {
            $item = TariffItem::query()->create([
                'tariff_catalog_id' => $catalog->id,
                'code' => $code,
                'description' => $name,
                'unit_price_minor' => $feeMinor,
                'vat_rate_bp' => $vatBp,
                'unit' => 'procedure',
                'requires_service_documentation' => false,
                'active' => true,
            ]);

            $procedure = DentalProcedure::query()->create([
                'tariff_item_id' => $item->id,
                'tooth_scoped' => $toothScoped,
            ]);

            $this->auditCatalog('dental.procedure.created', $procedure, $item, $actor);

            return $procedure;
        });
    }

    /**
     * Edit a procedure's name / fee / VAT / active + tooth-scope. Past CHARGES are unaffected
     * because the charge snapshotted the fee at capture (billing's D-F2 discipline).
     */
    public function update(User $actor, DentalProcedure $procedure, string $name, int $feeMinor, int $vatBp, bool $toothScoped, bool $active): DentalProcedure
    {
        $this->authorize($actor);
        $this->assertSameTenant($procedure);
        $this->assertInputs($procedure->tariffItem->code, $name, $feeMinor, $vatBp);

        DB::transaction(function () use ($procedure, $name, $feeMinor, $vatBp, $toothScoped, $active, $actor): void {
            $item = $procedure->tariffItem;
            $item->forceFill([
                'description' => $name,
                'unit_price_minor' => $feeMinor,
                'vat_rate_bp' => $vatBp,
                'active' => $active,
            ])->save();

            $procedure->forceFill(['tooth_scoped' => $toothScoped])->save();

            $this->auditCatalog('dental.procedure.updated', $procedure, $item, $actor);
        });

        return $procedure->refresh();
    }

    /**
     * The tenant's dental procedures with their tariff item (code/name/fee/vat/active).
     *
     * @return Collection<int, DentalProcedure>
     */
    public function list(): Collection
    {
        return DentalProcedure::query()
            ->with('tariffItem')
            ->get()
            ->sortBy(fn (DentalProcedure $p): string => (string) $p->tariffItem?->code)
            ->values();
    }

    private function assertInputs(string $code, string $name, int $feeMinor, int $vatBp): void
    {
        if (trim($code) === '' || trim($name) === '') {
            throw new DentalException('A dental procedure needs a code and a name.');
        }
        if ($feeMinor < 0 || $vatBp < 0) {
            throw new DentalException('Fee and VAT must not be negative.');
        }
    }

    private function authorize(User $actor): void
    {
        if (! Gate::forUser($actor)->allows('billing.manage')) {
            throw new AuthorizationException('This user cannot manage the dental fee schedule.');
        }
    }

    private function assertSameTenant(DentalProcedure $procedure): void
    {
        if ($procedure->tenant_id !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute('dental_procedure_id', (string) $procedure->id);
        }
    }

    private function auditCatalog(string $action, DentalProcedure $procedure, TariffItem $item, User $actor): void
    {
        // A fee-schedule change is administrative/financial — NOT clinical interpretation.
        $this->audit->record([
            'actor_type' => 'user',
            'actor_id' => (string) $actor->id,
            'action' => $action,
            'resource_type' => 'dental_procedure',
            'resource_id' => $procedure->id,
            'context' => [
                'code' => $item->code,
                'name' => $item->description,
                'unit_price_minor' => $item->unit_price_minor,
                'vat_rate_bp' => $item->vat_rate_bp,
                'tooth_scoped' => $procedure->tooth_scoped,
                'active' => $item->active,
            ],
        ]);
    }
}
