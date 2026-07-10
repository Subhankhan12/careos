<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Billing\Models\Charge;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\TariffCatalog;
use Modules\Billing\Models\TariffItem;
use Modules\Billing\Services\ChargeCaptureService;
use Modules\Billing\Services\ChargeValidator;
use Modules\Billing\Services\DunningService;
use Modules\Billing\Services\IssueService;
use Modules\Billing\Services\PaymentService;
use Modules\Clinical\Models\ClinicalNote;
use Modules\Clinical\Models\Encounter;
use Modules\Nursing\Models\Visit;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientService;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\SettingsService;
use Modules\Platform\Services\TenantContext;
use Modules\Scheduling\Models\Resource;

/**
 * THE SIMULATED MONTH (Phase F exit criterion): one realistic June 2026 for a
 * tenant, generated entirely through the real F.1-F.6 services — effective-dated
 * tariff versions with a mid-month boundary, encounter- and visit-based charge
 * capture, a real validation violation corrected before invoicing, six gapless
 * consecutive invoices (one multi-rate VAT), full/partial/over payments, an
 * allocation reversal, a partial credit note, and a level-1 dunning fee.
 *
 * The paired test `simulated month: full billing cycle reconciles to the unit`
 * then proves all six reconciliation invariants hold with delta_minor === 0.
 */
class SimulatedBillingMonthSeeder extends Seeder
{
    public const TENANT_SLUG = 'sim-june';

    public const PERIOD = '2026-06';

    public const BOUNDARY = '2026-06-15';

    /** Six issued invoices, keyed for the payment/credit/dunning steps. */
    private array $invoices = [];

    public function run(): void
    {
        // The month is June 2026; freeze "now" inside it so every service that
        // stamps now() (invoice drafts, credit notes, allocations) lands in the
        // period. Laravel's test harness resets this in tearDown.
        Carbon::setTestNow(Carbon::parse('2026-06-28 12:00:00'));

        $tenant = Tenant::query()->create([
            'name' => 'Simulated June Clinic',
            'slug' => self::TENANT_SLUG,
            'region' => 'eu',
            'status' => 'active',
        ]);
        app(TenantContext::class)->set($tenant);

        $actor = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
        RoleAssignment::query()->create([
            'user_id' => $actor->id,
            'role_id' => Role::query()->where('key', 'billing')->firstOrFail()->id,
        ]);

        $branch = Branch::query()->create([
            'name' => 'Sim Main Branch',
            'code' => 'SIM',
            'timezone' => 'Europe/Zurich',
        ]);
        $staff = StaffProfile::query()->create([
            'user_id' => $actor->id,
            'first_name' => 'Sim',
            'last_name' => 'Clinician',
            'display_name' => 'Sim Clinician',
            'profession' => 'doctor',
            'primary_branch_id' => $branch->id,
            'status' => StaffProfile::STATUS_ACTIVE,
        ]);
        $resource = Resource::query()->create([
            'type' => Resource::TYPE_PRACTITIONER,
            'name' => 'Sim Nurse',
            'staff_profile_id' => $staff->id,
            'branch_id' => $branch->id,
            'active' => true,
        ]);

        [$p1, $p2, $p3] = $this->patients();
        $this->tariffVersions();

        $capture = app(ChargeCaptureService::class);
        $validator = app(ChargeValidator::class);
        $issuer = app(IssueService::class);
        $payments = app(PaymentService::class);

        // ------------------------------------------------------------------
        // Charges. v1 prices apply through 2026-06-15; v2 from 2026-06-16.
        // ------------------------------------------------------------------
        $p1FirstHalf = $this->encounterCharges($capture, $p1, $staff, $branch, $actor, [
            '2026-06-02' => ['CONS', 'LAB'],
            '2026-06-05' => ['CONS', 'PHYS'],
            '2026-06-09' => ['CONS', 'LAB', 'PHYS'],
            '2026-06-12' => ['CONS', 'LAB', 'PHYS'],
        ]);
        $p1SecondHalf = $this->encounterCharges($capture, $p1, $staff, $branch, $actor, [
            '2026-06-18' => ['CONS', 'PHYS'],
            '2026-06-23' => ['CONS', 'LAB', 'PHYS'],
        ]);

        $p2FirstHalf = $this->encounterCharges($capture, $p2, $staff, $branch, $actor, [
            '2026-06-03' => ['CONS', 'LAB'],
            '2026-06-06' => ['CONS', 'PHYS'],
        ]);

        // The real violation: ADDON captured without its required BASE code.
        $violationEncounter = $this->encounter($p2, $staff, $branch, '2026-06-10');
        $addonCharge = $capture->captureFromEncounter($violationEncounter, 'ADDON', 1, $actor);

        $result = $validator->validateForPatientPeriod($p2, '2026-06-01', self::BOUNDARY, $actor);
        if (collect($result['violations'])->where('reason_code', ChargeValidator::REASON_REQUIRED_CODE_MISSING)->isEmpty()) {
            throw new \RuntimeException('Simulated month expected a REQUIRED_CODE_MISSING violation before correction.');
        }

        // Correction before invoicing: capture the missing BASE code, revalidate.
        $baseCharge = $capture->captureFromEncounter($violationEncounter, 'BASE', 1, $actor);
        $result = $validator->validateForPatientPeriod($p2, '2026-06-01', self::BOUNDARY, $actor);
        if ($result['violations'] !== []) {
            throw new \RuntimeException('Simulated month expected the violation to be corrected.');
        }

        $p2SecondHalf = $this->encounterCharges($capture, $p2, $staff, $branch, $actor, [
            '2026-06-16' => ['CONS', 'LAB'],
            '2026-06-25' => ['CONS', 'LAB'],
        ]);
        // One quantity-2 line so the credit note can be a true partial.
        $physQty2 = $capture->captureFromEncounter(
            $this->encounter($p2, $staff, $branch, '2026-06-20'),
            'PHYS',
            2,
            $actor,
        );
        $p2SecondHalf[] = $physQty2;

        $p3FirstHalf = $this->visitCharges($capture, $p3, $branch, $resource, $actor, [
            '2026-06-02' => ['HOME'],
            '2026-06-04' => ['HOME', 'LAB'],
            '2026-06-08' => ['HOME'],
            '2026-06-11' => ['HOME', 'LAB'],
        ]);
        $p3SecondHalf = $this->visitCharges($capture, $p3, $branch, $resource, $actor, [
            '2026-06-17' => ['HOME'],
            '2026-06-19' => ['HOME', 'LAB'],
            '2026-06-24' => ['HOME', 'LAB'],
            '2026-06-26' => ['HOME', 'LAB', 'PHYS'],
        ]);

        // Validate everything cleanly before invoicing.
        foreach ([$p1, $p2, $p3] as $patient) {
            $result = $validator->validateForPatientPeriod($patient, '2026-06-01', '2026-06-30', $actor);
            if ($result['violations'] !== []) {
                throw new \RuntimeException('Simulated month charges must validate cleanly before invoicing.');
            }
        }

        // ------------------------------------------------------------------
        // Six invoices, issued in order => numbers 1..6, no gaps.
        // INV-1 is due 2026-06-13 so it reaches dunning level 1 (+14) on 06-27.
        // ------------------------------------------------------------------
        $this->invoices[1] = $this->issue($issuer, $p1, $p1FirstHalf, $actor, '2026-06-13', '2026-06-13');
        $this->invoices[2] = $this->issue($issuer, $p2, [...$p2FirstHalf, $addonCharge->refresh(), $baseCharge->refresh()], $actor, '2026-06-14', '2026-06-30');
        $this->invoices[3] = $this->issue($issuer, $p3, $p3FirstHalf, $actor, '2026-06-14', '2026-06-30');
        $this->invoices[4] = $this->issue($issuer, $p1, $p1SecondHalf, $actor, '2026-06-26', '2026-06-30');
        $this->invoices[5] = $this->issue($issuer, $p2, $p2SecondHalf, $actor, '2026-06-26', '2026-06-30');
        $this->invoices[6] = $this->issue($issuer, $p3, $p3SecondHalf, $actor, '2026-06-27', '2026-06-30');

        // ------------------------------------------------------------------
        // Payments: full (INV-2), partial (INV-3), overpayment (INV-4, the
        // 2500 remainder stays visibly unallocated), and a misallocation to
        // INV-5 reversed then correctly allocated to INV-6.
        // ------------------------------------------------------------------
        $full = $payments->record($this->invoices[2]->total_minor, Payment::METHOD_BANK_TRANSFER, $actor, $p2, 'Full payer', null, '2026-06-18');
        $payments->allocate($full, $this->invoices[2], $this->invoices[2]->total_minor, $actor);

        $partial = $payments->record(intdiv($this->invoices[3]->total_minor, 2), Payment::METHOD_CARD, $actor, $p3, 'Partial payer', null, '2026-06-20');
        $payments->allocate($partial, $this->invoices[3], intdiv($this->invoices[3]->total_minor, 2), $actor);

        $over = $payments->record($this->invoices[4]->total_minor + 2500, Payment::METHOD_BANK_TRANSFER, $actor, $p1, 'Overpayer', null, '2026-06-27');
        $payments->allocate($over, $this->invoices[4], $this->invoices[4]->total_minor, $actor);

        $misdirected = $payments->record($this->invoices[6]->total_minor, Payment::METHOD_BANK_TRANSFER, $actor, $p3, 'Reversal payer', null, '2026-06-27');
        $mistake = $payments->allocate($misdirected, $this->invoices[5], 1000, $actor);
        $payments->reverseAllocation($mistake, 'Allocated to the wrong invoice', $actor);
        $payments->allocate($misdirected, $this->invoices[6], $this->invoices[6]->total_minor, $actor);

        // ------------------------------------------------------------------
        // Partial credit note against INV-5: one unit of the quantity-2 line.
        // ------------------------------------------------------------------
        $creditedLine = $this->invoices[5]->lines()->where('quantity', 2)->firstOrFail();
        $issuer->creditNote($this->invoices[5]->refresh(), [[
            'invoice_line_id' => $creditedLine->id,
            'quantity' => 1,
        ]], 'Partial correction: one session not delivered', $actor);

        // ------------------------------------------------------------------
        // Dunning: INV-1 (due 2026-06-13, unpaid) crosses L1 (+14) on 06-27.
        // The level fee is a NEW draft charge captured through the real path.
        // ------------------------------------------------------------------
        app(SettingsService::class)->set(DunningService::SETTINGS_KEY, [
            'channel' => 'email',
            'levels' => [[
                'level' => 1,
                'days_past_due' => 14,
                'template' => 'Your invoice is overdue. Please arrange payment.',
                'fee_code' => 'DUNFEE',
            ]],
        ], 'array');

        $events = app(DunningService::class)->evaluate($tenant, '2026-06-27', $actor, deliver: false);
        if (count($events) !== 1 || $events[0]->level !== 1) {
            throw new \RuntimeException('Simulated month expected exactly one level-1 dunning event.');
        }
    }

    /**
     * @return array{0: Patient, 1: Patient, 2: Patient}
     */
    private function patients(): array
    {
        $service = app(PatientService::class);

        return [
            $service->create(['first_name' => 'Erika', 'last_name' => 'Encounter', 'date_of_birth' => '1972-03-04', 'sex' => 'female']),
            $service->create(['first_name' => 'Viktor', 'last_name' => 'Violation', 'date_of_birth' => '1985-08-19', 'sex' => 'male']),
            $service->create(['first_name' => 'Nadia', 'last_name' => 'Nursing', 'date_of_birth' => '1948-11-30', 'sex' => 'female']),
        ];
    }

    /**
     * Two effective-dated versions of the same catalog key: v1 through the
     * boundary, v2 after it — same codes, different prices.
     */
    private function tariffVersions(): void
    {
        $items = [
            // code => [description, v1 price, v2 price, vat bp]
            'CONS' => ['standard consultation', 5000, 5500, 810],
            'PHYS' => ['physiotherapy session', 3000, 3200, 810],
            'LAB' => ['laboratory panel', 2500, 2600, 1900],
            'HOME' => ['home nursing care session', 4500, 4700, 0],
            'BASE' => ['base procedure', 6000, 6300, 810],
            'ADDON' => ['anesthesia addon', 1500, 1600, 810],
            'DUNFEE' => ['dunning fee level 1', 1500, 1500, 0],
        ];
        $rules = [[
            'type' => ChargeValidator::RULE_REQUIRES_CODE,
            'code' => 'ADDON',
            'requires' => 'BASE',
        ]];

        foreach ([
            1 => ['valid_from' => '2026-01-01', 'valid_to' => self::BOUNDARY, 'price_index' => 1],
            2 => ['valid_from' => '2026-06-16', 'valid_to' => null, 'price_index' => 2],
        ] as $version => $window) {
            $catalog = TariffCatalog::query()->create([
                'key' => 'eu-generic',
                'name' => 'EU Generic v'.$version,
                'version' => $version,
                'valid_from' => $window['valid_from'],
                'valid_to' => $window['valid_to'],
                'status' => TariffCatalog::STATUS_ACTIVE,
                'rules' => $rules,
            ]);

            foreach ($items as $code => [$description, $v1Price, $v2Price, $vatBp]) {
                TariffItem::query()->create([
                    'tariff_catalog_id' => $catalog->id,
                    'code' => $code,
                    'description' => $description,
                    'unit_price_minor' => $window['price_index'] === 1 ? $v1Price : $v2Price,
                    'vat_rate_bp' => $vatBp,
                    'unit' => 'session',
                    'requires_service_documentation' => false,
                    'active' => true,
                ]);
            }
        }
    }

    private function encounter(Patient $patient, StaffProfile $staff, Branch $branch, string $date): Encounter
    {
        $encounter = Encounter::query()->create([
            'patient_id' => $patient->id,
            'practitioner_id' => $staff->id,
            'branch_id' => $branch->id,
            'appointment_id' => null,
            'type' => Encounter::TYPE_CONSULTATION,
            'started_at' => $date.' 09:00:00',
            'status' => Encounter::STATUS_OPEN,
            'reason_for_visit' => 'Simulated month',
        ]);

        ClinicalNote::query()->create([
            'encounter_id' => $encounter->id,
            'patient_id' => $patient->id,
            'author_id' => $staff->id,
            'subjective' => 'Attended as scheduled.',
            'objective' => 'Documented findings.',
            'assessment' => 'Documented assessment.',
            'plan' => 'Documented services delivered.',
            'status' => ClinicalNote::STATUS_SIGNED,
            'signed_at' => $date.' 10:00:00',
            'signed_by' => $staff->user_id,
            'version' => 1,
        ]);

        return $encounter;
    }

    /**
     * @param  array<string, list<string>>  $plan  date => codes
     * @return list<Charge>
     */
    private function encounterCharges(
        ChargeCaptureService $capture,
        Patient $patient,
        StaffProfile $staff,
        Branch $branch,
        User $actor,
        array $plan,
    ): array {
        $charges = [];

        foreach ($plan as $date => $codes) {
            $encounter = $this->encounter($patient, $staff, $branch, $date);
            foreach ($codes as $code) {
                $charges[] = $capture->captureFromEncounter($encounter, $code, 1, $actor);
            }
        }

        return $charges;
    }

    /**
     * @param  array<string, list<string>>  $plan  date => codes
     * @return list<Charge>
     */
    private function visitCharges(
        ChargeCaptureService $capture,
        Patient $patient,
        Branch $branch,
        Resource $resource,
        User $actor,
        array $plan,
    ): array {
        $charges = [];

        foreach ($plan as $date => $codes) {
            $visit = Visit::query()->create([
                'planned_visit_id' => null,
                'patient_id' => $patient->id,
                'resource_id' => $resource->id,
                'branch_id' => $branch->id,
                'scheduled_start_at' => $date.' 09:00:00',
                'checked_in_at' => $date.' 09:05:00',
                'checked_out_at' => $date.' 10:05:00',
                'status' => Visit::STATUS_COMPLETED,
                'client_visit_uuid' => 'sim-'.strtolower((string) Str::ulid()),
            ]);

            foreach ($codes as $code) {
                $charges[] = $capture->captureFromVisit($visit, $code, 1, $actor);
            }
        }

        return $charges;
    }

    /**
     * @param  list<Charge>  $charges
     */
    private function issue(IssueService $issuer, Patient $patient, array $charges, User $actor, string $issueDate, string $dueDate): Invoice
    {
        $draft = $issuer->createDraftFromCharges(
            $patient,
            collect($charges)->map(fn ($charge) => $charge->refresh()),
            $actor,
            Invoice::PAYER_SELF_PAY,
            null,
            Carbon::parse($issueDate),
            Carbon::parse($dueDate),
        );

        return $issuer->issue($draft, $actor);
    }
}
