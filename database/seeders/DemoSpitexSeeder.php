<?php

namespace Database\Seeders;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\EncounterFactory;
use Database\Factories\StaffProfileFactory;
use Database\Factories\VitalFactory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Modules\AiCore\Models\KbArticle;
use Modules\AiCore\Services\ApprovalQueue;
use Modules\AiCore\Services\AutonomyPolicy;
use Modules\Billing\Models\Charge;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\TariffItem;
use Modules\Billing\Services\ChargeCaptureService;
use Modules\Billing\Services\ChargeValidator;
use Modules\Billing\Services\DunningService;
use Modules\Billing\Services\EuGenericTariffSeeder;
use Modules\Billing\Services\IssueService;
use Modules\Billing\Services\PaymentService;
use Modules\Clinical\Models\ClinicalNote;
use Modules\Clinical\Models\Encounter;
use Modules\Clinical\Models\Order;
use Modules\Clinical\Models\OrderableItem;
use Modules\Clinical\Services\CarePlanService;
use Modules\Clinical\Services\ClinicalListService;
use Modules\Clinical\Services\ClinicalNoteService;
use Modules\Clinical\Services\ClinicalTaskService;
use Modules\Clinical\Services\EncounterService;
use Modules\Clinical\Services\MedicationService;
use Modules\Clinical\Services\OrderableItemService;
use Modules\Clinical\Services\OrderService;
use Modules\Comms\Models\Thread;
use Modules\Comms\Services\ThreadService;
use Modules\Nursing\Models\Competency;
use Modules\Nursing\Models\Incident;
use Modules\Nursing\Models\NurseConstraint;
use Modules\Nursing\Models\PlannedVisit;
use Modules\Nursing\Models\ServiceAgreement;
use Modules\Nursing\Models\Visit;
use Modules\Nursing\Models\VisitNote;
use Modules\Nursing\Models\VisitPlan;
use Modules\Nursing\Models\VisitTask;
use Modules\Nursing\Models\VisitVital;
use Modules\Nursing\Services\CompetencyService;
use Modules\Nursing\Services\ServiceAgreementService;
use Modules\Nursing\Services\TimesheetService;
use Modules\Nursing\Services\VisitAssignmentService;
use Modules\Nursing\Services\VisitPlanGenerator;
use Modules\Nursing\Services\VisitService;
use Modules\Patients\Models\ConsentTemplate;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PatientContact;
use Modules\Patients\Services\ConsentService;
use Modules\Patients\Services\PatientService;
use Modules\Patients\Services\PortalAccessService;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Plan;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\SettingsService;
use Modules\Platform\Services\TenantContext;
use Modules\Scheduling\Models\Resource;
use Modules\Scheduling\Models\ResourceAvailability;
use Modules\Scheduling\Models\Service;
use Modules\Scheduling\Services\AppointmentService;
use Modules\Scheduling\Services\BookingService;
use Modules\Scheduling\Services\ServiceCatalog;
use RuntimeException;

/**
 * Spitex Sonnengarten — a Swiss home-care agency's operating week, as a COMPANION
 * demo tenant to Praxis Lindenhof (P0P.G16). Chosen over extending the clinic
 * seeder so a Spitex coordinator sees an agency shaped like their own operation
 * — a nurse roster with competencies, recurring home visits, proof-of-visit,
 * vitals trends across visits, and a billing month that closes — while the
 * clinic demo stays intact. Both tenants can coexist in one database.
 *
 * Everything here is invented: Swiss/German names that belong to nobody.
 * No real patient or staff data ever enters this file.
 *
 * HONESTY NOTE (demo-truth boundary): the agency bills EU-GENERIC. The Swiss
 * statutory KVG/KLV reimbursement pack is DEFERRED pending discovery (see
 * DEFERRED.md) — nothing here implies claims, insurer submissions, eRx, or
 * electronic lab connectivity. Lab results are MANUAL entries; that is what is
 * built, and that is all the demo shows.
 *
 * Same discipline as the P.1 clinic seeder (D-066):
 *   - Idempotent by tenant slug: if the tenant exists, nothing runs.
 *   - Seeded through the REAL services as normal tenant actors, so the audit
 *     chain is real and verifies end to end.
 *   - `now()` is never rewound; business dates are passed explicitly. Billing
 *     lives in the PREVIOUS full calendar month and reconciles to the unit;
 *     dispatch and the live surface sit in the CURRENT week.
 *
 * Stand up the demo with:
 *   php artisan db:seed --class=DemoSpitexSeeder
 */
class DemoSpitexSeeder extends Seeder
{
    public const TENANT_SLUG = 'spitex-sonnengarten';

    public const TENANT_NAME = 'Spitex Sonnengarten';

    public const BRANCH_NAME = 'Zürich Wipkingen';

    public const TIMEZONE = 'Europe/Zurich';

    public const CURRENCY = 'EUR';

    public const STAFF_PASSWORD = 'demo-password';

    public const PORTAL_PASSWORD = 'demo-portal-password';

    private Tenant $tenant;

    private Branch $branch;

    /** @var array<string, User> */
    private array $users = [];

    /** @var array<string, StaffProfile> */
    private array $staff = [];

    /** @var array<string, resource> */
    private array $resources = [];

    /** @var array<string, Service> */
    private array $services = [];

    /** @var array<string, Patient> */
    private array $patients = [];

    /** @var array<string, Competency> */
    private array $competencies = [];

    /** @var array<string, ServiceAgreement> */
    private array $agreements = [];

    /** @var array<string, list<Visit>> executed visits keyed by patient key */
    private array $completedVisits = [];

    private ?Thread $draftThread = null;

    private ?PlannedVisit $unassignedPalliativeVisit = null;

    private CarbonImmutable $periodStart;

    private CarbonImmutable $weekStart;

    /** The previous full calendar month — the billing period that reconciles. */
    public static function periodStart(): CarbonImmutable
    {
        return CarbonImmutable::today()->subMonthNoOverflow()->startOfMonth();
    }

    public static function period(): string
    {
        return self::periodStart()->format('Y-m');
    }

    public function run(): void
    {
        if (Tenant::query()->where('slug', self::TENANT_SLUG)->exists()) {
            return;
        }

        $this->periodStart = self::periodStart();
        $this->weekStart = CarbonImmutable::today()->startOfWeek(CarbonInterface::MONDAY);

        $this->provisionTenant();
        $this->seedStaff();
        $this->seedCompetencies();
        $this->seedServicesAndResources();
        $this->seedTariff();
        $this->seedPatients();
        $this->seedAppointments();
        $this->seedClinical();
        $this->seedNursing();
        $this->seedBilling();
        $this->seedComms();
        $this->seedAiCore();
    }

    /** Day `$n` of the demo billing period. */
    private function day(int $n): CarbonImmutable
    {
        return $this->periodStart->addDays($n - 1);
    }

    /** Day `$n` (1 = Monday) of the current week. */
    private function weekday(int $n): CarbonImmutable
    {
        return $this->weekStart->addDays($n - 1);
    }

    // -----------------------------------------------------------------
    // Platform
    // -----------------------------------------------------------------

    private function provisionTenant(): void
    {
        $this->call(PlanCatalogSeeder::class);

        $this->tenant = Tenant::query()->create([
            'name' => self::TENANT_NAME,
            'slug' => self::TENANT_SLUG,
            'region' => 'eu',
            'status' => 'active',
            'plan_id' => Plan::query()->where('key', 'eu_pro')->value('id'),
        ]);

        app(TenantContext::class)->set($this->tenant);

        $settings = app(SettingsService::class);
        $settings->set('currency', self::CURRENCY);
        $settings->set('locale', 'de');
        $settings->set('timezone', self::TIMEZONE);

        $this->branch = Branch::query()->create([
            'name' => self::BRANCH_NAME,
            'code' => 'ZH-WIP',
            'timezone' => self::TIMEZONE,
        ]);
    }

    // -----------------------------------------------------------------
    // People: the roster
    // -----------------------------------------------------------------

    private function seedStaff(): void
    {
        $people = [
            ['key' => 'admin', 'role' => 'org_admin', 'first' => 'Regula', 'last' => 'Baumann', 'profession' => 'administrator'],
            ['key' => 'coordinator', 'role' => 'coordinator', 'first' => 'Margrit', 'last' => 'Keller', 'profession' => 'coordinator'],
            ['key' => 'office', 'role' => 'reception', 'first' => 'Franziska', 'last' => 'Marti', 'profession' => 'reception'],
            ['key' => 'billing', 'role' => 'billing', 'first' => 'Corinne', 'last' => 'Vogel', 'profession' => 'billing'],
            ['key' => 'nurse_brunner', 'role' => 'nurse', 'first' => 'Hans', 'last' => 'Brunner', 'profession' => 'nurse'],
            ['key' => 'nurse_silva', 'role' => 'nurse', 'first' => 'Ana', 'last' => 'Silva', 'profession' => 'nurse'],
            ['key' => 'nurse_huber', 'role' => 'nurse', 'first' => 'Verena', 'last' => 'Huber', 'profession' => 'nurse'],
            ['key' => 'nurse_steffen', 'role' => 'nurse', 'first' => 'Miriam', 'last' => 'Steffen', 'profession' => 'nurse'],
            ['key' => 'nurse_okafor', 'role' => 'nurse', 'first' => 'David', 'last' => 'Okafor', 'profession' => 'nurse'],
        ];

        foreach ($people as $person) {
            $user = User::factory()
                ->forTenant($this->tenant)
                ->twoFactorEnabled()
                ->create([
                    'name' => $person['first'].' '.$person['last'],
                    'email' => Str::slug($person['first'].' '.$person['last'], '.').'@spitex-sonnengarten.test',
                    'password' => bcrypt(self::STAFF_PASSWORD),
                ]);

            RoleAssignment::query()->create([
                'user_id' => $user->id,
                'role_id' => Role::query()->where('key', $person['role'])->firstOrFail()->id,
                'branch_id' => null,
            ]);

            $this->users[$person['key']] = $user;
            $this->staff[$person['key']] = StaffProfileFactory::new()
                ->forUser($user)
                ->atBranch($this->branch)
                ->named($person['first'], $person['last'])
                ->profession($person['profession'])
                ->create();
        }
    }

    // -----------------------------------------------------------------
    // Competencies (P.12): the agency's own list + per-nurse grants
    // -----------------------------------------------------------------

    /**
     * The generic starter set (wound_care/catheter_care/injection hard,
     * dementia_care/palliative soft — the AGENCY's enforcement to change).
     * The per-nurse grants happen in grantCompetencies(), after the
     * practitioner resources they attach to exist.
     */
    private function seedCompetencies(): void
    {
        app(CompetencyService::class)->seedStarter();

        foreach (['wound_care', 'catheter_care', 'injection', 'dementia_care', 'palliative'] as $code) {
            $this->competencies[$code] = Competency::query()->where('code', $code)->firstOrFail();
        }
    }

    /**
     * Grants across the roster: some valid, one with a future expiry, one
     * EXPIRED — so dispatch shows a hard block, a soft warn, and an expiry.
     */
    private function grantCompetencies(): void
    {
        $competencyService = app(CompetencyService::class);
        $coordinator = $this->users['coordinator'];

        $grant = fn (string $nurseKey, string $code, ?string $expiresAt = null) => $competencyService->grant(
            $this->resources[$nurseKey],
            $this->competencies[$code],
            ['granted_at' => $this->periodStart->subYear()->toDateString(), 'expires_at' => $expiresAt],
            $coordinator,
        );

        // Hans Brunner: wound care + injections valid; catheter care EXPIRED
        // three weeks ago — an expired competency counts as not held.
        $grant('nurse_brunner', 'wound_care');
        $grant('nurse_brunner', 'injection');
        $grant('nurse_brunner', 'catheter_care', CarbonImmutable::today()->subDays(21)->toDateString());

        // Ana Silva: injections + palliative + dementia care.
        $grant('nurse_silva', 'injection');
        $grant('nurse_silva', 'palliative');
        $grant('nurse_silva', 'dementia_care');

        // Verena Huber: wound + catheter care, one with a future expiry so the
        // admin screen shows a dated grant.
        $grant('nurse_huber', 'wound_care');
        $grant('nurse_huber', 'catheter_care', CarbonImmutable::today()->addDays(45)->toDateString());

        // Miriam Steffen: dementia care only. David Okafor: none yet — he is
        // the new hire the competency screens have work for.
        $grant('nurse_steffen', 'dementia_care');
    }

    // -----------------------------------------------------------------
    // Scheduling: services, resources, availability, constraints
    // -----------------------------------------------------------------

    private function seedServicesAndResources(): void
    {
        $catalog = app(ServiceCatalog::class);
        $branchIds = [$this->branch->id];

        $this->services['home'] = $catalog->create([
            'name' => 'Hauspflege-Einsatz 60 Minuten',
            'code' => 'HOME-60',
            'category' => 'nursing',
            'default_duration_minutes' => 60,
            'requires_resource_types' => [Resource::TYPE_PRACTITIONER],
            'bookable_online' => false,
            'active' => true,
        ], $branchIds);

        $this->services['beratung'] = $catalog->create([
            'name' => 'Beratungsgespräch 30 Minuten',
            'code' => 'BERATUNG-30',
            'category' => 'general',
            'default_duration_minutes' => 30,
            'requires_resource_types' => [Resource::TYPE_PRACTITIONER],
            'bookable_online' => false,
            'active' => true,
        ], $branchIds);

        foreach ([
            'nurse_brunner' => 'Hans Brunner',
            'nurse_silva' => 'Ana Silva',
            'nurse_huber' => 'Verena Huber',
            'nurse_steffen' => 'Miriam Steffen',
            'nurse_okafor' => 'David Okafor',
        ] as $key => $name) {
            $this->resources[$key] = Resource::query()->create([
                'type' => Resource::TYPE_PRACTITIONER,
                'name' => $name,
                'staff_profile_id' => $this->staff[$key]->id,
                'branch_id' => $this->branch->id,
                'active' => true,
            ]);

            foreach ([1, 2, 3, 4, 5] as $weekdayNumber) {
                ResourceAvailability::query()->create([
                    'resource_id' => $this->resources[$key]->id,
                    'weekday' => $weekdayNumber,
                    'start_time' => '07:00:00',
                    'end_time' => '18:00:00',
                    'is_available' => true,
                ]);
            }
        }

        foreach ([
            ['key' => 'vehicle_1', 'name' => 'Fahrzeug 1 — VW Caddy (ZH 318 645)'],
            ['key' => 'vehicle_2', 'name' => 'Fahrzeug 2 — Dacia Dokker (ZH 402 118)'],
        ] as $vehicle) {
            $this->resources[$vehicle['key']] = Resource::query()->create([
                'type' => Resource::TYPE_VEHICLE,
                'name' => $vehicle['name'],
                'branch_id' => $this->branch->id,
                'active' => true,
            ]);
        }

        foreach ([
            'nurse_brunner' => ['qualification' => 'rn', 'hours' => 38.00, 'travel' => 30],
            'nurse_silva' => ['qualification' => 'rn', 'hours' => 34.00, 'travel' => 30],
            'nurse_huber' => ['qualification' => 'rn', 'hours' => 38.00, 'travel' => 25],
            'nurse_steffen' => ['qualification' => 'care_assistant', 'hours' => 26.00, 'travel' => 20],
            'nurse_okafor' => ['qualification' => 'care_assistant', 'hours' => 22.00, 'travel' => 20],
        ] as $key => $constraint) {
            NurseConstraint::query()->create([
                'resource_id' => $this->resources[$key]->id,
                'qualification' => $constraint['qualification'],
                'max_hours_per_week' => $constraint['hours'],
                'max_travel_minutes_between_visits' => $constraint['travel'],
            ]);
        }

        // The practitioner resources now exist, so the roster's competency
        // grants can attach to them.
        $this->grantCompetencies();
    }

    // -----------------------------------------------------------------
    // Billing catalog: EU-Generic, single version. CH/KVG is DEFERRED —
    // the demo bills EU-Generic honestly (see the class docblock).
    // -----------------------------------------------------------------

    private function seedTariff(): void
    {
        $v1 = app(EuGenericTariffSeeder::class)->seed($this->tenant, $this->periodStart->subYear()->toDateString());

        $v1->forceFill([
            'rules' => [
                'market_pack' => 'eu_generic',
                'validation_rules' => [
                    ['type' => ChargeValidator::RULE_REQUIRES_CODE, 'code' => 'TRAVEL-15', 'requires' => 'HOME-60'],
                    ['type' => ChargeValidator::RULE_MAX_QUANTITY_PER_PERIOD, 'code' => 'CONSULT-30', 'max' => 2, 'period' => 'day'],
                ],
            ],
        ])->save();

        foreach ([
            ['code' => 'HOUSEKEEPING-60', 'description' => 'Hauswirtschaftliche Leistung, 60 Minuten', 'unit_price_minor' => 4800, 'vat_rate_bp' => 810, 'unit' => 'session', 'requires_service_documentation' => false, 'active' => true],
            ['code' => 'DUNNING-FEE', 'description' => 'Mahngebühr, Stufe 1', 'unit_price_minor' => 1500, 'vat_rate_bp' => 0, 'unit' => 'item', 'requires_service_documentation' => false, 'active' => true],
        ] as $item) {
            TariffItem::query()->create(['tariff_catalog_id' => $v1->id, ...$item]);
        }
    }

    // -----------------------------------------------------------------
    // Patients
    // -----------------------------------------------------------------

    private function seedPatients(): void
    {
        $this->seedConsentTemplates();
        $patientService = app(PatientService::class);

        $curated = [
            'margrit' => ['Margrit', 'Ackermann', '1938-05-04', 'female'],
            'frick' => ['Hans-Peter', 'Frick', '1946-09-17', 'male'],
            'elsbeth' => ['Elsbeth', 'Moser', '1931-12-01', 'female'],
            'rosa' => ['Rosa', 'Bernasconi', '1935-03-22', 'female'],
            'walter' => ['Walter', 'Schnyder', '1942-07-08', 'male'],
            'trudi' => ['Trudi', 'Gasser', '1948-01-26', 'female'],
            'josef' => ['Josef', 'Kunz', '1957-10-13', 'male'],
            'heidi' => ['Heidi', 'Brülhart', '1944-06-30', 'female'],
        ];

        $streets = ['Nordstrasse', 'Lehenstrasse', 'Rousseaustrasse', 'Dammstrasse', 'Röschibachstrasse'];
        $index = 0;

        foreach ($curated as $key => [$firstName, $lastName, $dob, $sex]) {
            $this->patients[$key] = $patientService->create(
                [
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'date_of_birth' => $dob,
                    'sex' => $sex,
                    'preferred_language' => 'de',
                    'status' => Patient::STATUS_ACTIVE,
                ],
                [
                    ['type' => PatientContact::TYPE_EMAIL, 'value' => Str::slug($firstName.' '.$lastName, '.').'@example.test', 'is_primary' => true],
                    ['type' => PatientContact::TYPE_PHONE, 'value' => sprintf('+41 44 %03d %02d %02d', 300 + $index, 20 + $index, 30 + $index), 'is_primary' => true],
                    ['type' => PatientContact::TYPE_ADDRESS, 'line1' => $streets[$index % count($streets)].' '.(7 + $index * 4), 'city' => 'Zürich', 'postal' => (string) (8037 + ($index % 2)), 'country' => 'CH', 'is_primary' => true],
                ],
                $index % 2 === 0 ? [['system' => 'ahv', 'value' => '756.'.sprintf('%04d.%04d.%02d', 3000 + $index, 4000 + $index, 20 + $index)]] : [],
                $index % 3 === 0 ? [[
                    'payer_name' => 'CSS Versicherung',
                    'member_id' => 'M'.sprintf('%08d', 83_200_000 + $index),
                    'plan' => 'Standard',
                    'coverage_type' => 'private_insurance',
                    'priority' => 1,
                ]] : [],
            );

            $index++;
        }

        $consents = app(ConsentService::class);
        $office = $this->users['office'];

        foreach (['margrit', 'frick', 'elsbeth', 'rosa', 'trudi'] as $key) {
            $patient = $this->patients[$key];
            $consents->grant($patient, 'comms', $patient->first_name.' '.$patient->last_name, $office);
        }

        foreach (['margrit', 'frick'] as $key) {
            $patient = $this->patients[$key];
            $consents->grant($patient, 'portal', $patient->first_name.' '.$patient->last_name, $office);
        }

        $portal = app(PortalAccessService::class);

        foreach (['margrit', 'frick'] as $key) {
            $patient = $this->patients[$key];
            $invite = $portal->invite($patient, Str::slug($patient->first_name.' '.$patient->last_name, '.').'@example.test');
            $portal->acceptInvite($invite->plainToken, $invite->otp, self::PORTAL_PASSWORD);
        }
    }

    private function seedConsentTemplates(): void
    {
        ConsentTemplate::query()->create([
            'key' => 'portal',
            'title' => 'Klientenportal — Zugang',
            'body' => 'I consent to accessing my care record, documents, and invoices through the Spitex Sonnengarten client portal.',
            'version' => 1,
            'scope_keys' => ['portal.access'],
            'is_active' => true,
        ]);

        ConsentTemplate::query()->create([
            'key' => 'comms',
            'title' => 'Kommunikation per E-Mail',
            'body' => 'I consent to Spitex Sonnengarten contacting me by email about my visits and care.',
            'version' => 1,
            'scope_keys' => ['comms.email'],
            'is_active' => true,
        ]);
    }

    // -----------------------------------------------------------------
    // Scheduling: a few office consultations, including a real no-show
    // -----------------------------------------------------------------

    private function seedAppointments(): void
    {
        $booking = app(BookingService::class);
        $lifecycle = app(AppointmentService::class);
        $office = $this->users['office'];

        $book = fn (string $patientKey, CarbonImmutable $startsAt, string $nurseKey) => $booking->book(
            $this->services['beratung']->id,
            $this->patients[$patientKey]->id,
            $this->branch->id,
            $startsAt,
            [$this->resources[$nurseKey]->id],
            $office,
        );

        // An intake consultation, completed through the real lifecycle.
        $completed = $book('trudi', $this->weekday(1)->setTime(14, 0), 'nurse_huber');
        $completed = $lifecycle->confirm($completed, $office);
        $completed = $lifecycle->arrive($completed, $office);
        $completed = $lifecycle->start($completed, $office);
        $lifecycle->complete($completed, $office);

        // A real no-show — the reporting metrics have something honest to count.
        $lifecycle->noShow(
            $book('josef', $this->weekday(2)->setTime(15, 30), 'nurse_silva'),
            $office,
            'Nicht erschienen; telefonisch nicht erreichbar.',
        );

        // A booked consultation later in the week, left alone.
        $book('heidi', $this->weekday(4)->setTime(10, 0), 'nurse_silva');
    }

    // -----------------------------------------------------------------
    // Clinical: documented lists, a signed+amended note, care plan, orders
    // -----------------------------------------------------------------

    private function seedClinical(): void
    {
        $lists = app(ClinicalListService::class);
        $medications = app(MedicationService::class);
        $recorder = $this->staff['nurse_silva'];
        $actor = $this->users['nurse_silva'];

        // Documented problems — facts recorded, never interpreted.
        foreach ([
            ['margrit', 'Type 2 diabetes mellitus', 'E11', '2011-03-18'],
            ['margrit', 'Essential hypertension', 'I10', '2008-06-02'],
            ['frick', 'Chronic venous leg ulcer', 'L97', '2024-11-20'],
            ['elsbeth', 'Dementia, previously diagnosed elsewhere', 'F03', '2022-04-07'],
            ['rosa', 'Metastatic breast cancer, palliative situation documented by the oncology service', 'C50', '2025-01-15'],
            ['walter', 'Long-term indwelling urinary catheter', 'Z93.5', '2023-09-12'],
        ] as [$patientKey, $description, $code, $onset]) {
            $lists->recordProblem($this->patients[$patientKey], $recorder, $actor, [
                'description' => $description,
                'code' => $code,
                'onset_date' => $onset,
            ]);
        }

        // A severe allergy so the banner is loud, plus a moderate one.
        $lists->recordAllergy($this->patients['margrit'], $recorder, $actor, [
            'substance' => 'Penicillin',
            'reaction' => 'Anaphylaxis requiring adrenaline and hospital admission.',
            'severity' => 'severe',
            'verified_at' => now()->subYears(3),
        ]);

        $lists->recordAllergy($this->patients['frick'], $recorder, $actor, [
            'substance' => 'Latex',
            'reaction' => 'Contact urticaria at dressing sites.',
            'severity' => 'moderate',
        ]);

        // Medications with the doses as prescribed elsewhere — documented, never derived.
        foreach ([
            ['margrit', 'Insulin glargine', '12 IE', 'subcutaneous', 'once daily in the morning', '2019-02-11'],
            ['margrit', 'Metformin', '500 mg', 'oral', 'twice daily with meals', '2011-04-01'],
            ['frick', 'Paracetamol', '1 g', 'oral', 'up to three times daily as needed', '2024-11-25'],
            ['rosa', 'Morphine sulfate', '10 mg', 'oral', 'twice daily as prescribed by the palliative care service', '2025-06-30'],
        ] as [$patientKey, $name, $dose, $route, $frequency, $startedOn]) {
            $medications->record($this->patients[$patientKey], $recorder, $actor, [
                'name' => $name,
                'dose_text' => $dose,
                'route' => $route,
                'frequency_text' => $frequency,
                'started_on' => $startedOn,
            ]);
        }

        // Two clinic-side vitals for Margrit — the unified P.13 trend then shows
        // BOTH sources (clinic + visit) in one series.
        foreach ([5, 19] as $dayOfMonth) {
            VitalFactory::new()
                ->forPatient($this->patients['margrit'])
                ->recordedBy($recorder)
                ->recordedAt($this->day($dayOfMonth)->setTime(10, 15)->toDateTimeString())
                ->create(['systolic' => 138 - $dayOfMonth % 4, 'diastolic' => 82, 'heart_rate' => 74]);
        }

        $this->seedAssessmentNote();
        $this->seedCarePlan();
        $this->seedOrders();
    }

    /**
     * A live nursing assessment: drafted, signed, then amended with a reason —
     * the chart shows both versions.
     */
    private function seedAssessmentNote(): void
    {
        $encounters = app(EncounterService::class);
        $notes = app(ClinicalNoteService::class);
        $nurse = $this->staff['nurse_silva'];
        $actor = $this->users['nurse_silva'];

        $encounter = $encounters->open(
            $this->patients['margrit'],
            $nurse,
            $this->branch,
            null,
            Encounter::TYPE_HOME_VISIT,
            $actor,
            'Halbjährliches Pflegeassessment.',
        );

        $signed = $notes->sign($notes->saveDraft($encounter, $nurse, [
            'subjective' => 'Reports managing the morning insulin routine with support. No hypoglycaemic episodes reported since the last assessment.',
            'objective' => 'Alert and oriented. Skin at injection sites intact. Blood pressure recorded seated at 136/84.',
            'assessment' => 'Care needs unchanged from the previous assessment; documented conditions stable as described by the patient.',
            'plan' => 'Continue the daily morning insulin visit as agreed. Review the arrangement at the next assessment.',
        ], $actor), $actor);

        $amendment = $notes->amend(
            $signed,
            ['objective' => 'Alert and oriented. Skin at injection sites intact. Blood pressure recorded seated at 136/84. Capillary blood glucose recorded at 7.8 mmol/l before breakfast.'],
            'Der Blutzuckerwert wurde beim Signieren versehentlich nicht übernommen.',
            $nurse,
            $actor,
        );
        $notes->sign($amendment, $actor);

        $encounters->close($encounter, $actor);
    }

    private function seedCarePlan(): void
    {
        $carePlans = app(CarePlanService::class);
        $tasks = app(ClinicalTaskService::class);
        $actor = $this->users['nurse_silva'];

        $plan = $carePlans->create(
            $this->patients['margrit'],
            $this->staff['nurse_silva'],
            $actor,
            [
                'title' => 'Betreuungsplan — Diabetes-Routine und Selbstständigkeit',
                'started_on' => $this->periodStart->toDateString(),
            ],
            [
                ['description' => 'Morning insulin administered with the documented routine, seven days a week.', 'target_date' => $this->weekStart->addWeeks(8)->toDateString()],
                ['description' => 'Blood pressure recorded at each visit and noted in the record.', 'target_date' => $this->weekStart->addWeeks(4)->toDateString()],
            ],
        );

        $tasks->create($actor, $this->staff['nurse_brunner'], [
            'patient_id' => $this->patients['margrit']->id,
            'care_plan_id' => $plan->id,
            'title' => 'Injektionsstellen bei nächstem Einsatz kontrollieren',
            'description' => 'Check the injection sites at the next morning visit and record the finding.',
            'due_at' => $this->weekday(3)->setTime(8, 30)->toDateTimeString(),
            'priority' => 'normal',
        ]);

        $tasks->create($actor, $this->staff['coordinator'], [
            'patient_id' => $this->patients['margrit']->id,
            'title' => 'Halbjahresgespräch mit Angehörigen vereinbaren',
            'due_at' => $this->weekday(5)->setTime(16, 0)->toDateTimeString(),
            'priority' => 'high',
        ]);
    }

    /**
     * Structured orders (P.11) with MANUAL results only — electronic lab
     * connectivity is a stub and the demo does not pretend otherwise.
     */
    private function seedOrders(): void
    {
        $items = app(OrderableItemService::class);
        $orders = app(OrderService::class);
        $admin = $this->users['admin'];
        $nurse = $this->users['nurse_silva'];

        $items->seedStarter();
        $swab = $items->create([
            'category' => OrderableItem::CATEGORY_LAB,
            'code' => 'WOUND-SWAB',
            'name' => 'Wundabstrich',
            'specimen_or_modality' => 'Abstrich',
        ], $admin);

        // Resulted but NOT reviewed — the review worklist has a real item.
        $swabOrder = $orders->place($this->patients['frick'], null, $swab, [
            'priority' => 'routine',
            'clinical_note' => 'Wundabstrich bei verzögerter Heilung, wie mit dem Hausarzt besprochen.',
        ], $nurse);
        $orders->transition($swabOrder, Order::STATUS_COLLECTED, $nurse);
        $orders->recordResult($swabOrder, ['value' => 'Mischflora. Kein Nachweis von MRSA.'], $nurse);

        // A second order, resulted and reviewed — both worklist states exist.
        $urinalysis = OrderableItem::query()->where('code', 'URINALYSIS')->firstOrFail();
        $urineOrder = $orders->place($this->patients['walter'], null, $urinalysis, [
            'priority' => 'routine',
            'clinical_note' => 'Kontrolle bei liegendem Dauerkatheter.',
        ], $nurse);
        $orders->recordResult($urineOrder, ['value' => 'Leukozyten negativ, Nitrit negativ.'], $nurse);
        $orders->markReviewed($urineOrder->refresh(), $nurse);
    }

    // -----------------------------------------------------------------
    // Nursing: agreements, recurring plans, a fully assigned week,
    // executed visits with proof, tasks, vitals trends, an incident,
    // and timesheets from actuals
    // -----------------------------------------------------------------

    private function seedNursing(): void
    {
        $this->seedAgreements();

        // Materialize every plan from its start through the end of the current
        // week, then assign the roster. Competency enforcement is live here:
        // every hard requirement below is satisfied by the assigned nurse.
        $insulin = $this->materializePlan('insulin', 'FREQ=DAILY', '07:30:00', '08:30:00', 30, $this->day(16));
        $wound = $this->materializePlan('wound', 'FREQ=WEEKLY;BYDAY=MO,WE,FR', '09:30:00', '11:00:00', 60, $this->periodStart);
        $catheter = $this->materializePlan('catheter', 'FREQ=WEEKLY;BYDAY=WE', '11:30:00', '13:00:00', 60, $this->periodStart);
        $bath = $this->materializePlan('bath', 'FREQ=WEEKLY;BYDAY=TU', '10:00:00', '11:30:00', 60, $this->periodStart);
        $palliative = $this->materializePlan('palliative', 'FREQ=WEEKLY;BYDAY=TU,FR', '17:00:00', '18:30:00', 60, $this->day(8));

        $this->assignVisits($insulin, 'nurse_brunner');
        $this->assignVisits($wound, 'nurse_huber');
        $this->assignVisits($catheter, 'nurse_huber');

        // The bath visits go to Miriam (holds dementia_care) — except the ones
        // after the executed window, which the coordinator gives to David. He
        // lacks the SOFT dementia_care competency, so the assignment SUCCEEDS
        // with a warning: the dispatcher proceeded past an advisory, and the
        // audit context records it. (The strictly-after-day-27 bound keeps the
        // executed visits with the nurse who worked them in every calendar
        // alignment of the week.)
        foreach ($bath as $visit) {
            $nurseKey = $visit->scheduled_date->gt($this->day(27)) ? 'nurse_okafor' : 'nurse_steffen';
            app(VisitAssignmentService::class)->assign($visit->refresh(), $this->resources[$nurseKey], $this->users['coordinator']);
        }

        // The palliative visits go to Ana — except the current week's Friday
        // visit, deliberately left UNASSIGNED: the dispatch agent's pending
        // proposal (seedAiCore) suggests Ana for it, and a human decides.
        foreach ($palliative as $visit) {
            if ($visit->scheduled_date->toDateString() === $this->weekday(5)->toDateString()) {
                $this->unassignedPalliativeVisit = $visit;

                continue;
            }

            app(VisitAssignmentService::class)->assign($visit->refresh(), $this->resources['nurse_silva'], $this->users['coordinator']);
        }

        // Execute the previous month's visits end to end (GPS proof, tasks,
        // raw vitals, notes). The current week stays assigned-but-unworked, so
        // the dispatch board shows the week ahead.
        $this->executeVisits('margrit', 'insulin', 'nurse_brunner', 30, taskText: 'Insulin gemäss dokumentiertem Schema verabreicht');
        $this->executeVisits('frick', 'wound', 'nurse_huber', 60, taskText: 'Verbandwechsel gemäss Pflegeplan', manualFallbackIndex: 2, notDoneIndex: 4);
        $this->executeVisits('walter', 'catheter', 'nurse_huber', 60, taskText: 'Katheterpflege durchgeführt');
        $this->executeVisits('elsbeth', 'bath', 'nurse_steffen', 60, taskText: 'Unterstützung bei der Körperpflege', notDoneIndex: 1);
        $this->executeVisits('rosa', 'palliative', 'nurse_silva', 60, taskText: 'Palliative Grundpflege gemäss Plan');

        $this->seedIncident();
        $this->seedTimesheets();
    }

    private function seedAgreements(): void
    {
        $agreements = app(ServiceAgreementService::class);
        $coordinator = $this->users['coordinator'];

        $create = function (string $key, string $patientKey, string $funding, ?string $payer, float $hours, CarbonImmutable $startsOn, string $frequencyText, string $qualification, array $requiredCompetencies, int $duration) use ($agreements, $coordinator): void {
            $agreement = $agreements->create([
                'patient_id' => $this->patients[$patientKey]->id,
                'branch_id' => $this->branch->id,
                'funding_type' => $funding,
                'payer_name' => $payer,
                'authorized_hours_per_week' => $hours,
                'starts_on' => $startsOn->toDateString(),
                'status' => ServiceAgreement::STATUS_DRAFT,
            ], [[
                'service_id' => $this->services['home']->id,
                'planned_frequency_text' => $frequencyText,
                'required_qualification' => $qualification,
                'required_competencies' => $requiredCompetencies,
                'duration_minutes' => $duration,
            ]], $coordinator);

            $agreements->activate($agreement, $coordinator);
            $this->agreements[$key] = $agreement;
        };

        $create('insulin', 'margrit', ServiceAgreement::FUNDING_PRIVATE_INSURANCE, 'CSS Versicherung', 3.5, $this->day(16), 'Täglich morgens — Insulingabe nach dokumentiertem Schema', 'rn', ['injection'], 30);
        $create('wound', 'frick', ServiceAgreement::FUNDING_SELF_PAY, null, 3.0, $this->periodStart, 'Montag, Mittwoch und Freitag — Verbandwechsel Unterschenkel', 'rn', ['wound_care'], 60);
        $create('catheter', 'walter', ServiceAgreement::FUNDING_SELF_PAY, null, 1.0, $this->periodStart, 'Mittwochs — Katheterpflege', 'rn', ['catheter_care'], 60);
        $create('bath', 'elsbeth', ServiceAgreement::FUNDING_SELF_PAY, null, 1.5, $this->periodStart, 'Dienstags — Unterstützung bei der Körperpflege', 'care_assistant', ['dementia_care'], 60);
        $create('palliative', 'rosa', ServiceAgreement::FUNDING_PRIVATE_INSURANCE, 'CSS Versicherung', 3.0, $this->day(8), 'Dienstag und Freitag abends — palliative Grundpflege', 'rn', ['palliative'], 60);
    }

    /**
     * @return list<PlannedVisit>
     */
    private function materializePlan(
        string $agreementKey,
        string $rrule,
        string $windowStart,
        string $windowEnd,
        int $durationMinutes,
        CarbonImmutable $startsOn,
    ): array {
        $agreement = $this->agreements[$agreementKey];
        $agreementService = $agreement->agreementServices()->firstOrFail();

        $plan = VisitPlan::query()->create([
            'service_agreement_id' => $agreement->id,
            'agreement_service_id' => $agreementService->id,
            'rrule' => $rrule,
            'timezone' => self::TIMEZONE,
            'window_start_time' => $windowStart,
            'window_end_time' => $windowEnd,
            'duration_minutes' => $durationMinutes,
            'starts_on' => $startsOn->toDateString(),
            'active' => true,
        ]);

        app(VisitPlanGenerator::class)->materialize($plan, $startsOn, $this->weekStart->addDays(6));

        $visits = PlannedVisit::query()
            ->where('visit_plan_id', $plan->id)
            ->orderBy('scheduled_date')
            ->get()
            ->all();

        // Straight-line coordinates around Wipkingen for the deterministic
        // travel check — the documented D-E3 stand-in for road routing.
        foreach ($visits as $index => $visit) {
            $visit->forceFill([
                'location_latitude' => 47.3930 + ($index % 4) * 0.003,
                'location_longitude' => 8.5230 + ($index % 4) * 0.002,
            ])->save();
        }

        return $visits;
    }

    /**
     * @param  list<PlannedVisit>  $visits
     */
    private function assignVisits(array $visits, string $nurseKey): void
    {
        $assignments = app(VisitAssignmentService::class);
        $coordinator = $this->users['coordinator'];

        foreach ($visits as $visit) {
            $assignments->assign($visit->refresh(), $this->resources[$nurseKey], $coordinator);
        }
    }

    /**
     * Work the previous month's visits end to end: GPS check-in, a task done
     * (or documented not done), raw vitals — Margrit's BP forms a real
     * multi-visit trend — a care note, GPS check-out.
     */
    private function executeVisits(
        string $patientKey,
        string $agreementKey,
        string $nurseKey,
        int $durationMinutes,
        string $taskText,
        ?int $manualFallbackIndex = null,
        ?int $notDoneIndex = null,
    ): void {
        $visitService = app(VisitService::class);
        $nurse = $this->users[$nurseKey];
        $nurseResource = $this->resources[$nurseKey];
        $agreement = $this->agreements[$agreementKey];
        $agreementService = $agreement->agreementServices()->firstOrFail();

        $planned = PlannedVisit::query()
            ->whereIn('visit_plan_id', VisitPlan::query()->where('service_agreement_id', $agreement->id)->pluck('id'))
            ->whereDate('scheduled_date', '<=', $this->day(27)->toDateString())
            ->orderBy('scheduled_date')
            ->get();

        foreach ($planned as $index => $plannedVisit) {
            $visit = $visitService->createFromPlannedVisit($plannedVisit, 'spitex-'.strtolower((string) Str::ulid()));

            $arrival = $plannedVisit->window_start_at->copy()->addMinutes(2 + ($index % 6));
            $departure = $arrival->copy()->addMinutes($durationMinutes - 2 + ($index % 5));

            $location = $index === $manualFallbackIndex
                ? 'Kein GPS-Empfang im Untergeschoss; Ankunft manuell erfasst.'
                : [
                    'latitude' => (float) $plannedVisit->location_latitude,
                    'longitude' => (float) $plannedVisit->location_longitude,
                    'accuracy_meters' => 7.5,
                ];

            $visitService->checkIn($visit, $nurse, $location, $arrival);

            // Raw values only. Margrit's series varies gently across visits so
            // the P.13 trend view has a real line to draw; nothing interprets it.
            VisitVital::query()->create([
                'visit_id' => $visit->id,
                'patient_id' => $visit->patient_id,
                'recorded_at' => $arrival->copy()->addMinutes(5),
                'systolic' => 126 + (($index * 3) % 11),
                'diastolic' => 74 + (($index * 2) % 7),
                'heart_rate' => 66 + ($index % 9),
                'temperature_c' => $index % 3 === 0 ? 36.5 + ($index % 4) / 10 : null,
                'spo2' => $index % 2 === 0 ? 95 + ($index % 4) : null,
            ]);

            $task = VisitTask::query()->create([
                'visit_id' => $visit->id,
                'agreement_service_id' => $agreementService->id,
                'description' => $taskText,
            ]);

            if ($index === $notDoneIndex) {
                $task->forceFill([
                    'status' => VisitTask::STATUS_NOT_DONE,
                    'not_done_reason' => $patientKey === 'elsbeth'
                        ? 'Klientin lehnte die Dusche heute ab; Angehörige informiert.'
                        : 'Klient wünschte den Verbandwechsel erst am Folgetermin.',
                ])->save();
            } else {
                $task->forceFill([
                    'status' => VisitTask::STATUS_DONE,
                    'completed_at' => $departure->copy()->subMinutes(6),
                ])->save();
            }

            VisitNote::query()->create([
                'visit_id' => $visit->id,
                'patient_id' => $visit->patient_id,
                'body' => $this->visitNoteBody($patientKey, $index),
                'author_resource_id' => $nurseResource->id,
                'recorded_at' => $departure->copy()->subMinutes(3),
            ]);

            $visitService->checkOut($visit, $nurse, [
                'latitude' => (float) $plannedVisit->location_latitude,
                'longitude' => (float) $plannedVisit->location_longitude,
                'accuracy_meters' => 8.0,
            ], $departure);

            $this->completedVisits[$patientKey][] = $visit->refresh();
        }
    }

    private function visitNoteBody(string $patientKey, int $index): string
    {
        $bodies = [
            'margrit' => [
                'Insulin given as documented. Injection site intact. Patient ate breakfast during the visit.',
                'Morning routine completed as planned. Blood pressure recorded and noted.',
                'Insulin given as documented. Patient reports sleeping well.',
            ],
            'frick' => [
                'Dressing changed as scheduled. Old dressing dry on removal. Photo of the wound taken for the record at the last practice visit.',
                'Dressing changed. Surrounding skin intact. Patient mobilising with the stick.',
                'Dressing changed as scheduled. No complaints raised during the visit.',
            ],
            'walter' => [
                'Catheter care performed as documented. Drainage bag changed.',
                'Catheter care performed. No complaints raised.',
            ],
            'elsbeth' => [
                'Support with washing and dressing. Daughter present for part of the visit.',
                'Support with personal care. Client calm and cooperative today.',
            ],
            'rosa' => [
                'Evening care given as planned. Positioned comfortably before leaving.',
                'Evening care given. Husband present; questions answered about the night routine.',
            ],
        ];

        $set = $bodies[$patientKey];

        return $set[$index % count($set)];
    }

    /**
     * One factual incident report: what happened, reporter-selected severity,
     * nothing assessed or advised by the system.
     */
    private function seedIncident(): void
    {
        $visit = $this->completedVisits['frick'][1] ?? $this->completedVisits['frick'][0];

        Incident::query()->create([
            'visit_id' => $visit->id,
            'patient_id' => $visit->patient_id,
            'reported_by_resource_id' => $this->resources['nurse_huber']->id,
            'occurred_at' => $visit->checked_in_at?->copy()->addMinutes(15) ?? now(),
            'category' => Incident::CATEGORY_FALL,
            'severity' => Incident::SEVERITY_MEDIUM,
            'description' => 'Klient beim Aufstehen aus dem Sessel seitlich weggeknickt und zu Boden gerutscht. Keine sichtbare Verletzung. Angehörige und Hausarztpraxis informiert.',
            'status' => Incident::STATUS_OPEN,
        ]);
    }

    private function seedTimesheets(): void
    {
        $timesheets = app(TimesheetService::class);

        foreach (['nurse_brunner', 'nurse_huber', 'nurse_steffen', 'nurse_silva'] as $nurseKey) {
            $lines = $timesheets->generateFromVisits(
                $this->resources[$nurseKey],
                $this->periodStart,
                $this->day(27),
            );

            foreach ($lines as $line) {
                if ($line->discrepancy_flags === null || $line->discrepancy_flags === []) {
                    $timesheets->approve($line, $this->users['coordinator']);
                }
            }
        }
    }

    // -----------------------------------------------------------------
    // Billing: the whole EU-Generic cycle, closing to the unit
    // -----------------------------------------------------------------

    private function seedBilling(): void
    {
        $capture = app(ChargeCaptureService::class);
        $validator = app(ChargeValidator::class);
        $issuer = app(IssueService::class);
        $payments = app(PaymentService::class);
        $actor = $this->users['billing'];

        // Visit charges from the visits actually worked: the home-care unit
        // plus its travel unit, per completed visit.
        $chargesByPatient = [];
        foreach ($this->completedVisits as $patientKey => $visits) {
            foreach ($visits as $visit) {
                $chargesByPatient[$patientKey][] = $capture->captureFromVisit($visit, 'HOME-60', 1, $actor);
                $chargesByPatient[$patientKey][] = $capture->captureFromVisit($visit, 'TRAVEL-15', 1, $actor);
            }
        }

        // Two intake assessments as encounter charges.
        foreach ([['margrit', 3], ['rosa', 8]] as [$patientKey, $dayOfMonth]) {
            $encounter = $this->billableEncounter($this->patients[$patientKey], $this->day($dayOfMonth));
            $chargesByPatient[$patientKey][] = $capture->captureFromEncounter($encounter, 'CONSULT-30', 1, $actor);
        }

        // A quantity-2 housekeeping line so the credit note below is a true partial.
        $chargesByPatient['walter'][] = $capture->captureManual(
            $this->patients['walter'],
            $this->branch,
            $this->day(18)->toDateString(),
            'HOUSEKEEPING-60',
            2,
            $actor,
        );

        foreach (array_keys($chargesByPatient) as $patientKey) {
            $result = $validator->validateForPatientPeriod(
                $this->patients[$patientKey],
                $this->periodStart->toDateString(),
                $this->periodStart->endOfMonth()->toDateString(),
                $actor,
            );

            if ($result['violations'] !== []) {
                throw new RuntimeException('Spitex demo charges must validate cleanly before invoicing.');
            }
        }

        // Six invoices, issued in order — numbers 1..6, no gaps. INV-1 is due
        // on day 13 so it crosses dunning level 1 (+14 days) on day 27.
        $isEarly = fn (Charge $charge): bool => $charge->refresh()->service_date->lte($this->day(13));
        $elsbethEarly = array_values(array_filter($chargesByPatient['elsbeth'], $isEarly));
        $elsbethLate = array_values(array_filter($chargesByPatient['elsbeth'], fn (Charge $charge): bool => ! $isEarly($charge)));

        $invoices = [
            1 => $this->issue($issuer, 'elsbeth', $elsbethEarly, $actor, 13, 13),
            2 => $this->issue($issuer, 'margrit', $chargesByPatient['margrit'], $actor, 28, 28),
            3 => $this->issue($issuer, 'frick', $chargesByPatient['frick'], $actor, 28, 28),
            4 => $this->issue($issuer, 'rosa', $chargesByPatient['rosa'], $actor, 28, 28),
            5 => $this->issue($issuer, 'walter', $chargesByPatient['walter'], $actor, 28, 28),
            6 => $this->issue($issuer, 'elsbeth', $elsbethLate, $actor, 28, 28),
        ];

        // Payments: one in full, one partial, one overpayment whose remainder
        // stays visibly unallocated. INV-1 and INV-6 stay open.
        $full = $payments->record($invoices[3]->total_minor, Payment::METHOD_BANK_TRANSFER, $actor, $this->patients['frick'], 'Hans-Peter Frick', null, $this->day(20)->toDateString());
        $payments->allocate($full, $invoices[3], $invoices[3]->total_minor, $actor);

        $half = intdiv($invoices[4]->total_minor, 2);
        $partial = $payments->record($half, Payment::METHOD_BANK_TRANSFER, $actor, $this->patients['rosa'], 'CSS Versicherung', null, $this->day(22)->toDateString());
        $payments->allocate($partial, $invoices[4], $half, $actor);

        $over = $payments->record($invoices[2]->total_minor + 1500, Payment::METHOD_CARD, $actor, $this->patients['margrit'], 'Margrit Ackermann', null, $this->day(27)->toDateString());
        $payments->allocate($over, $invoices[2], $invoices[2]->total_minor, $actor);

        // A partial credit note against the quantity-2 housekeeping line —
        // dated today, against the closed month, original untouched.
        $creditedLine = $invoices[5]->lines()->where('quantity', 2)->firstOrFail();
        $issuer->creditNote($invoices[5]->refresh(), [[
            'invoice_line_id' => $creditedLine->id,
            'quantity' => 1,
        ]], 'Eine hauswirtschaftliche Leistung wurde nicht erbracht.', $actor);

        $this->seedDunning($actor);
    }

    private function seedDunning(User $actor): void
    {
        app(SettingsService::class)->set(DunningService::SETTINGS_KEY, [
            'channel' => 'email',
            'levels' => [
                ['level' => 1, 'days_past_due' => 14, 'template' => 'Ihre Rechnung ist überfällig. Bitte veranlassen Sie die Zahlung.', 'fee_code' => 'DUNNING-FEE'],
                ['level' => 2, 'days_past_due' => 30, 'template' => 'Zweite Mahnung. Bitte veranlassen Sie die Zahlung umgehend.'],
            ],
        ], 'array');

        $events = app(DunningService::class)->evaluate($this->tenant, $this->day(27)->toDateString(), $actor, deliver: false);

        if (count($events) !== 1 || $events[0]->level !== 1) {
            throw new RuntimeException('The Spitex demo month expects exactly one level-1 dunning event.');
        }
    }

    private function billableEncounter(Patient $patient, CarbonImmutable $date): Encounter
    {
        $staff = $this->staff['nurse_silva'];

        $encounter = EncounterFactory::new()
            ->forPatient($patient)
            ->withPractitioner($staff)
            ->atBranch($this->branch)
            ->on($date->toDateString(), '09:00:00')
            ->create();

        ClinicalNote::query()->create([
            'encounter_id' => $encounter->id,
            'patient_id' => $patient->id,
            'author_id' => $staff->id,
            'subjective' => 'Care needs assessment attended as scheduled. No new concerns raised.',
            'objective' => 'Assessment performed and findings documented.',
            'assessment' => 'Care needs documented as discussed with the client and family.',
            'plan' => 'Services agreed and documented in the service agreement.',
            'status' => ClinicalNote::STATUS_SIGNED,
            'signed_at' => $date->setTime(9, 45),
            'signed_by' => $staff->user_id,
            'version' => 1,
        ]);

        return $encounter;
    }

    /**
     * @param  list<Charge>  $charges
     */
    private function issue(IssueService $issuer, string $patientKey, array $charges, User $actor, int $issueDay, int $dueDay): Invoice
    {
        $draft = $issuer->createDraftFromCharges(
            $this->patients[$patientKey],
            array_map(fn (Charge $charge): Charge => $charge->refresh(), $charges),
            $actor,
            Invoice::PAYER_SELF_PAY,
            null,
            $this->day($issueDay),
            $this->day($dueDay),
        );

        return $issuer->issue($draft, $actor);
    }

    // -----------------------------------------------------------------
    // Comms
    // -----------------------------------------------------------------

    private function seedComms(): void
    {
        $threads = app(ThreadService::class);
        $office = $this->users['office'];
        $margrit = $this->patients['margrit'];
        $frick = $this->patients['frick'];

        // An admin question, assigned and unanswered — the inbox agent's
        // pending draft (seedAiCore) references this thread.
        $this->draftThread = $threads->openPatientThread($margrit, 'Frage zur Einsatzzeit nächste Woche', $office);
        $threads->addPatientParticipant($this->draftThread, $margrit, $office);
        $threads->postPatientMessage($this->draftThread, $margrit, 'Guten Tag, könnte der Morgeneinsatz nächste Woche eine halbe Stunde später stattfinden? Meine Tochter holt mich am Dienstag ab.');
        $threads->assign($this->draftThread, $office, $office);

        // A clinical question: flagged for a clinician, never answered by the
        // office and never drafted by the agent (D-065).
        $clinical = $threads->openPatientThread($frick, 'Frage zur Wunde', $office);
        $threads->addPatientParticipant($clinical, $frick, $office);
        $threads->postPatientMessage($clinical, $frick, 'Guten Tag, die Wunde nässt seit gestern stärker. Soll ich den Verband selber wechseln?');
        $clinical->forceFill([
            'clinician_attention_at' => now(),
            'clinician_attention_reason' => 'clinical_question_requires_clinician',
        ])->save();

        // An internal thread — vehicles, rosters, never a patient.
        $internal = $threads->openInternalThread('Fahrzeug 1 — Service am Donnerstag', $this->users['admin']);
        $threads->addStaffParticipant($internal, $office, $this->users['admin']);
        $threads->postStaffMessage($internal, $this->users['admin'], 'Fahrzeug 1 ist am Donnerstag in der Werkstatt. Bitte die Morgentouren auf Fahrzeug 2 planen.');
        $threads->postStaffMessage($internal, $office, 'Notiert — die Touren am Donnerstag sind auf Fahrzeug 2 umgestellt.');
    }

    // -----------------------------------------------------------------
    // AiCore: pending approvals that have done nothing, and a KB
    // -----------------------------------------------------------------

    private function seedAiCore(): void
    {
        KbArticle::query()->create([
            'title' => 'Einsatzzeiten und Erreichbarkeit',
            'body' => "Spitex Sonnengarten, Zürich Wipkingen.\n\nEinsätze finden täglich zwischen 07:00 und 21:00 statt. Das Büro ist Montag bis Freitag von 08:00 bis 17:00 erreichbar.\n\nAusserhalb der Bürozeiten gilt die auf der Website publizierte Pikettnummer. In medizinischen Notfällen rufen Sie 144.",
            'tags' => ['einsatzzeiten', 'kontakt'],
            'is_active' => true,
        ]);

        KbArticle::query()->create([
            'title' => 'Rechnungen und Verrechnung',
            'body' => "Die Einsätze werden monatlich in Rechnung gestellt; die Rechnung ist im Klientenportal einsehbar.\n\nZahlungsfrist: 14 Tage ab Rechnungsdatum. Bei Fragen zur Rechnung wenden Sie sich bitte an das Büro.",
            'tags' => ['rechnungen', 'verrechnung'],
            'is_active' => true,
        ]);

        $queue = app(ApprovalQueue::class);

        // The dispatch agent proposes Ana for the unassigned Friday palliative
        // visit. The proposal assigns NOTHING until a human approves it.
        $queue->propose(
            'nursing.propose_assignments',
            [
                'date' => $this->unassignedPalliativeVisit?->scheduled_date->toDateString(),
                'branch_id' => $this->branch->id,
                'proposals' => [[
                    'visit_id' => $this->unassignedPalliativeVisit?->id,
                    'resource_id' => $this->resources['nurse_silva']->id,
                ]],
            ],
            $this->users['coordinator'],
            'nursing.dispatch_assignments',
            'dispatch',
            'Der Freitagabend-Einsatz bei Frau Bernasconi ist noch nicht zugeteilt; Ana Silva erfüllt Qualifikation und Kompetenzen.',
            AutonomyPolicy::APPROVE,
        );

        // An inbox draft for the admin question — waiting for a human to send.
        $queue->propose(
            'comms.draft_reply',
            ['thread_id' => $this->draftThread?->id],
            $this->users['office'],
            'comms.draft_reply',
            'inbox',
            'Eine Klientenanfrage im Posteingang ist unbeantwortet; ein Entwurf liegt zur Prüfung bereit.',
            AutonomyPolicy::SUGGEST,
        );
    }
}
