<?php

namespace Database\Seeders;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\CredentialFactory;
use Database\Factories\EncounterFactory;
use Database\Factories\PatientFactory;
use Database\Factories\StaffProfileFactory;
use Database\Factories\VitalFactory;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Modules\AiCore\Models\KbArticle;
use Modules\AiCore\Services\ApprovalQueue;
use Modules\AiCore\Services\AutonomyPolicy;
use Modules\Billing\Models\Charge;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\TariffCatalog;
use Modules\Billing\Models\TariffItem;
use Modules\Billing\Services\ChargeCaptureService;
use Modules\Billing\Services\ChargeValidator;
use Modules\Billing\Services\DunningService;
use Modules\Billing\Services\EuGenericTariffSeeder;
use Modules\Billing\Services\IssueService;
use Modules\Billing\Services\PaymentService;
use Modules\Clinical\Models\ClinicalNote;
use Modules\Clinical\Models\Encounter;
use Modules\Clinical\Models\RecallRule;
use Modules\Clinical\Models\Referral;
use Modules\Clinical\Services\CarePlanService;
use Modules\Clinical\Services\ClinicalListService;
use Modules\Clinical\Services\ClinicalNoteService;
use Modules\Clinical\Services\ClinicalTaskService;
use Modules\Clinical\Services\DocumentService;
use Modules\Clinical\Services\EncounterService;
use Modules\Clinical\Services\MedicationService;
use Modules\Clinical\Services\RecallEngine;
use Modules\Clinical\Services\ReferralService;
use Modules\Comms\Models\Thread;
use Modules\Comms\Services\NotificationService;
use Modules\Comms\Services\ThreadService;
use Modules\Nursing\Models\NurseConstraint;
use Modules\Nursing\Models\PlannedVisit;
use Modules\Nursing\Models\ServiceAgreement;
use Modules\Nursing\Models\SyncConflict;
use Modules\Nursing\Models\Visit;
use Modules\Nursing\Models\VisitNote;
use Modules\Nursing\Models\VisitPlan;
use Modules\Nursing\Models\VisitVital;
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
use Modules\Platform\Services\RbacProvisioner;
use Modules\Platform\Services\SettingsService;
use Modules\Platform\Services\TenantContext;
use Modules\Scheduling\Models\Appointment;
use Modules\Scheduling\Models\Resource;
use Modules\Scheduling\Models\ResourceAvailability;
use Modules\Scheduling\Models\Service;
use Modules\Scheduling\Services\AppointmentService;
use Modules\Scheduling\Services\BookingService;
use Modules\Scheduling\Services\ServiceCatalog;
use Modules\Scheduling\Services\WaitlistService;
use RuntimeException;

/**
 * Praxis Lindenhof — ONE richly populated demo tenant for design, sales, and
 * design partners. Everything here is invented: Swiss/EU names that belong to
 * nobody, plausible medications with documented doses, realistic clinic hours.
 * No real patient data ever enters this file.
 *
 * Shape of the demo:
 *   - Billing lives in the PREVIOUS full calendar month — a closed month that
 *     reconciles to the unit ({@see self::period()}). Every date is in the past,
 *     the tariff-version boundary sits mid-month, and the paired test proves all
 *     six reconciliation invariants at delta_minor === 0.
 *   - Scheduling, dispatch, and the live clinical surface sit in the CURRENT
 *     week, so whoever opens the demo sees a clinic with work in front of it.
 *
 * Idempotent: the tenant slug is the key. If Praxis Lindenhof already exists the
 * seeder does nothing at all, so a second run cannot duplicate a thing.
 *
 * Tenant creation runs through the real provisioning path — `Tenant::created`
 * fires {@see RbacProvisioner::provisionTenant()},
 * which seeds the starter roles in Phase A system mode so provisioning does not
 * pollute the tenant's audit chain. Everything after that runs as a normal
 * tenant-scoped actor, because a demo clinic should have a real audit trail.
 *
 * Time note: this seeder never moves `now()` backwards. `AuditService::verifyChain`
 * replays the chain ordered by `occurred_at`, so back-dating a write mid-run would
 * order the rows differently from how they were hash-linked and break the chain.
 * Business dates are passed explicitly as arguments instead.
 *
 * Run it with:
 *   php artisan db:seed --class=DemoClinicSeeder
 */
class DemoClinicSeeder extends Seeder
{
    public const TENANT_SLUG = 'praxis-lindenhof';

    public const TENANT_NAME = 'Praxis Lindenhof';

    public const BRANCH_NAME = 'Zürich Oberstrass';

    public const TIMEZONE = 'Europe/Zurich';

    public const CURRENCY = 'EUR';

    /** Demo logins are uniform and obviously fake; MFA is pre-enrolled. */
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

    /** @var list<Patient> */
    private array $patients = [];

    /** @var list<Visit> */
    private array $completedVisits = [];

    /** Reaches in_progress by having its encounter opened — see seedLiveConsult(). */
    private ?Appointment $appointmentInProgress = null;

    /** The unanswered patient thread the inbox agent has drafted a reply for. */
    private ?Thread $draftThread = null;

    private CarbonImmutable $periodStart;

    /** Last day the v1 tariff prices apply; v2 takes over the next day. */
    private CarbonImmutable $boundary;

    private CarbonImmutable $weekStart;

    /**
     * The billing period the demo reconciles: the previous full calendar month.
     * Every month has at least 28 days, so every day this seeder addresses
     * (2..27) exists regardless of when it runs.
     */
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
        $this->boundary = $this->day(15);
        $this->weekStart = CarbonImmutable::today()->startOfWeek(CarbonInterface::MONDAY);

        $this->provisionTenant();
        $this->seedStaff();
        $this->seedServicesAndResources();
        $this->seedTariffVersions();
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

        // Tenant::created fires RbacProvisioner::provisionTenant() — the real
        // provisioning path, which seeds starter roles in system mode.
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
            'code' => 'ZH-OBS',
            'timezone' => self::TIMEZONE,
        ]);
    }

    // -----------------------------------------------------------------
    // People
    // -----------------------------------------------------------------

    private function seedStaff(): void
    {
        $people = [
            ['key' => 'admin', 'role' => 'org_admin', 'first' => 'Andrea', 'last' => 'Lindenhof', 'profession' => 'administrator'],
            ['key' => 'doctor_brunner', 'role' => 'doctor', 'first' => 'Matthias', 'last' => 'Brunner', 'profession' => 'doctor', 'display' => 'Dr. med. Matthias Brunner'],
            ['key' => 'doctor_keller', 'role' => 'doctor', 'first' => 'Sofia', 'last' => 'Keller', 'profession' => 'doctor', 'display' => 'Dr. med. Sofia Keller'],
            ['key' => 'nurse_frei', 'role' => 'nurse', 'first' => 'Lena', 'last' => 'Frei', 'profession' => 'nurse'],
            ['key' => 'nurse_bianchi', 'role' => 'nurse', 'first' => 'Marco', 'last' => 'Bianchi', 'profession' => 'nurse'],
            ['key' => 'nurse_wyss', 'role' => 'nurse', 'first' => 'Anja', 'last' => 'Wyss', 'profession' => 'nurse'],
            ['key' => 'coordinator', 'role' => 'coordinator', 'first' => 'Petra', 'last' => 'Hofmann', 'profession' => 'coordinator'],
            ['key' => 'reception', 'role' => 'reception', 'first' => 'Nadia', 'last' => 'Steiner', 'profession' => 'reception'],
            ['key' => 'billing', 'role' => 'billing', 'first' => 'Thomas', 'last' => 'Ammann', 'profession' => 'billing'],
        ];

        foreach ($people as $person) {
            $user = User::factory()
                ->forTenant($this->tenant)
                ->twoFactorEnabled()
                ->create([
                    'name' => $person['display'] ?? $person['first'].' '.$person['last'],
                    'email' => Str::slug($person['first'].' '.$person['last'], '.').'@praxis-lindenhof.test',
                    'password' => bcrypt(self::STAFF_PASSWORD),
                ]);

            // branch_id null = all branches. A branch-scoped assignment only
            // answers gate checks that pass a branch, and plenty do not.
            RoleAssignment::query()->create([
                'user_id' => $user->id,
                'role_id' => Role::query()->where('key', $person['role'])->firstOrFail()->id,
                'branch_id' => null,
            ]);

            $this->users[$person['key']] = $user;
            $this->staff[$person['key']] = StaffProfileFactory::new()
                ->forUser($user)
                ->atBranch($this->branch)
                ->named($person['first'], $person['last'], $person['display'] ?? null)
                ->profession($person['profession'])
                ->create();
        }

        $this->seedCredentials();
    }

    /**
     * A credential vault with something to say: valid licences, two inside the
     * 30-day expiry-alert window, one already expired, one manually revoked.
     */
    private function seedCredentials(): void
    {
        CredentialFactory::new()->forStaff($this->staff['doctor_brunner'])->create();
        CredentialFactory::new()->forStaff($this->staff['doctor_keller'])->create();
        CredentialFactory::new()->forStaff($this->staff['nurse_frei'])->create();

        CredentialFactory::new()
            ->forStaff($this->staff['nurse_bianchi'])
            ->ofType('licence', 'Registered nurse licence')
            ->expiringInDays(11)
            ->create();

        CredentialFactory::new()
            ->forStaff($this->staff['nurse_wyss'])
            ->ofType('certificate', 'Basic life support certificate')
            ->expiringInDays(24)
            ->create();

        CredentialFactory::new()
            ->forStaff($this->staff['nurse_frei'])
            ->ofType('certificate', 'Wound care certificate')
            ->expiredDaysAgo(9)
            ->create();

        CredentialFactory::new()
            ->forStaff($this->staff['nurse_bianchi'])
            ->ofType('certificate', 'Driving permit — clinic vehicles')
            ->revoked()
            ->create();
    }

    // -----------------------------------------------------------------
    // Scheduling: services, resources, availability
    // -----------------------------------------------------------------

    private function seedServicesAndResources(): void
    {
        $catalog = app(ServiceCatalog::class);
        $branchIds = [$this->branch->id];

        $this->services['consult'] = $catalog->create([
            'name' => 'Sprechstunde 30 Minuten',
            'code' => 'CONSULT-30',
            'category' => 'general',
            'default_duration_minutes' => 30,
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 5,
            'requires_resource_types' => [Resource::TYPE_PRACTITIONER, Resource::TYPE_ROOM],
            'bookable_online' => true,
            'active' => true,
        ], $branchIds);

        $this->services['follow_up'] = $catalog->create([
            'name' => 'Kontrolle 15 Minuten',
            'code' => 'FOLLOWUP-15',
            'category' => 'general',
            'default_duration_minutes' => 15,
            'buffer_after_minutes' => 5,
            'requires_resource_types' => [Resource::TYPE_PRACTITIONER, Resource::TYPE_ROOM],
            'bookable_online' => true,
            'active' => true,
        ], $branchIds);

        $this->services['physio'] = $catalog->create([
            'name' => 'Physiotherapie 30 Minuten',
            'code' => 'PHYSIO-30',
            'category' => 'therapy',
            'default_duration_minutes' => 30,
            'requires_resource_types' => [Resource::TYPE_PRACTITIONER, Resource::TYPE_CHAIR],
            'bookable_online' => false,
            'active' => true,
        ], $branchIds);

        $this->services['telehealth'] = $catalog->create([
            'name' => 'Videosprechstunde 20 Minuten',
            'code' => 'TELE-20',
            'category' => 'telehealth',
            'default_duration_minutes' => 20,
            'requires_resource_types' => [Resource::TYPE_PRACTITIONER],
            'bookable_online' => true,
            'active' => true,
        ], $branchIds);

        $this->services['home'] = $catalog->create([
            'name' => 'Hausbesuch 60 Minuten',
            'code' => 'HOME-60',
            'category' => 'nursing',
            'default_duration_minutes' => 60,
            'requires_resource_types' => [Resource::TYPE_PRACTITIONER],
            'bookable_online' => false,
            'active' => true,
        ], $branchIds);

        // Practitioner resources — one per clinician.
        foreach ([
            'doctor_brunner' => 'Dr. Brunner',
            'doctor_keller' => 'Dr. Keller',
            'nurse_frei' => 'Lena Frei',
            'nurse_bianchi' => 'Marco Bianchi',
            'nurse_wyss' => 'Anja Wyss',
        ] as $key => $name) {
            $this->resources[$key] = Resource::query()->create([
                'type' => Resource::TYPE_PRACTITIONER,
                'name' => $name,
                'staff_profile_id' => $this->staff[$key]->id,
                'branch_id' => $this->branch->id,
                'active' => true,
            ]);
        }

        // Rooms, a treatment chair, and the two home-care vehicles.
        foreach ([
            ['key' => 'room_1', 'type' => Resource::TYPE_ROOM, 'name' => 'Sprechzimmer 1'],
            ['key' => 'room_2', 'type' => Resource::TYPE_ROOM, 'name' => 'Sprechzimmer 2'],
            ['key' => 'chair_1', 'type' => Resource::TYPE_CHAIR, 'name' => 'Behandlungsstuhl 1'],
            ['key' => 'vehicle_1', 'type' => Resource::TYPE_VEHICLE, 'name' => 'Fahrzeug 1 — VW Caddy (ZH 154 872)'],
            ['key' => 'vehicle_2', 'type' => Resource::TYPE_VEHICLE, 'name' => 'Fahrzeug 2 — Fiat Doblò (ZH 209 447)'],
        ] as $resource) {
            $this->resources[$resource['key']] = Resource::query()->create([
                'type' => $resource['type'],
                'name' => $resource['name'],
                'branch_id' => $this->branch->id,
                'active' => true,
            ]);
        }

        $this->seedResourceAvailability();
        $this->seedNurseConstraints();
    }

    private function seedResourceAvailability(): void
    {
        $bookable = ['doctor_brunner', 'doctor_keller', 'nurse_frei', 'nurse_bianchi', 'nurse_wyss', 'room_1', 'room_2', 'chair_1'];

        foreach ($bookable as $key) {
            foreach ([1, 2, 3, 4, 5] as $weekday) { // Carbon dayOfWeek: 1 = Monday
                ResourceAvailability::query()->create([
                    'resource_id' => $this->resources[$key]->id,
                    'weekday' => $weekday,
                    'start_time' => '08:00:00',
                    'end_time' => '18:00:00',
                    'is_available' => true,
                ]);
            }
        }

        // A date-specific closure, so the availability override path has a
        // real example: Dr. Keller is away next Friday.
        ResourceAvailability::query()->create([
            'resource_id' => $this->resources['doctor_keller']->id,
            'date' => $this->weekday(5)->addWeek()->toDateString(),
            'start_time' => '08:00:00',
            'end_time' => '18:00:00',
            'is_available' => false,
            'reason' => 'Fortbildung',
        ]);
    }

    private function seedNurseConstraints(): void
    {
        foreach ([
            'nurse_frei' => ['qualification' => 'rn', 'hours' => 38.00, 'travel' => 30],
            'nurse_bianchi' => ['qualification' => 'rn', 'hours' => 32.00, 'travel' => 25],
            'nurse_wyss' => ['qualification' => 'care_assistant', 'hours' => 24.00, 'travel' => 20],
        ] as $key => $constraint) {
            NurseConstraint::query()->create([
                'resource_id' => $this->resources[$key]->id,
                'qualification' => $constraint['qualification'],
                'max_hours_per_week' => $constraint['hours'],
                'max_travel_minutes_between_visits' => $constraint['travel'],
            ]);
        }
    }

    // -----------------------------------------------------------------
    // Billing: two effective-dated catalog versions across the boundary
    // -----------------------------------------------------------------

    /**
     * v1 runs from a year before the demo period to the mid-period boundary;
     * v2 takes over the next day with a price rise. Same codes, different
     * prices — so a charge's snapshot proves which version priced it.
     */
    private function seedTariffVersions(): void
    {
        // The real starter-catalog path builds v1 (HOME-60, CONSULT-30, TRAVEL-15).
        $v1 = app(EuGenericTariffSeeder::class)->seed($this->tenant, $this->periodStart->subYear()->toDateString());

        // The starter catalog's `rules` carry no validation rules; ChargeValidator
        // throws on an unrecognised rule type, so they are set explicitly here.
        $v1->forceFill([
            'valid_to' => $this->boundary->toDateString(),
            'rules' => $this->catalogRules(),
        ])->save();

        foreach ($this->extraItems(version: 1) as $item) {
            TariffItem::query()->create(['tariff_catalog_id' => $v1->id, ...$item]);
        }

        $v2 = TariffCatalog::query()->create([
            'key' => EuGenericTariffSeeder::CATALOG_KEY,
            'name' => 'EU-Generic Starter Catalog (v2)',
            'version' => 2,
            'currency' => self::CURRENCY,
            'valid_from' => $this->boundary->addDay()->toDateString(),
            'valid_to' => null,
            'status' => TariffCatalog::STATUS_ACTIVE,
            'rules' => $this->catalogRules(),
        ]);

        foreach ([...$this->starterItems(version: 2), ...$this->extraItems(version: 2)] as $item) {
            TariffItem::query()->create(['tariff_catalog_id' => $v2->id, ...$item]);
        }
    }

    /**
     * Deterministic, explainable validation rules — no AI decides any of this.
     *
     * @return array<string, mixed>
     */
    private function catalogRules(): array
    {
        return [
            'market_pack' => 'eu_generic',
            'validation_rules' => [
                ['type' => ChargeValidator::RULE_REQUIRES_CODE, 'code' => 'TRAVEL-15', 'requires' => 'HOME-60'],
                ['type' => ChargeValidator::RULE_MAX_QUANTITY_PER_PERIOD, 'code' => 'CONSULT-30', 'max' => 2, 'period' => 'day'],
            ],
        ];
    }

    /**
     * The starter codes, repriced for v2. Mirrors EuGenericTariffSeeder's items.
     *
     * @return list<array<string, mixed>>
     */
    private function starterItems(int $version): array
    {
        return [
            ['code' => 'HOME-60', 'description' => 'Home care visit, 60 minutes', 'unit_price_minor' => $version === 1 ? 7500 : 7800, 'vat_rate_bp' => 0, 'unit' => 'session', 'requires_service_documentation' => true, 'active' => true],
            ['code' => 'CONSULT-30', 'description' => 'Clinical consultation, 30 minutes', 'unit_price_minor' => $version === 1 ? 6000 : 6300, 'vat_rate_bp' => 0, 'unit' => 'session', 'requires_service_documentation' => true, 'active' => true],
            ['code' => 'TRAVEL-15', 'description' => 'Travel time, 15 minute unit', 'unit_price_minor' => $version === 1 ? 1200 : 1250, 'vat_rate_bp' => 0, 'unit' => '15min', 'requires_service_documentation' => false, 'active' => true],
        ];
    }

    /**
     * The VAT-bearing codes plus the dunning fee. Three rates across the
     * catalog (0 / 8.1% / 19%) give the demo a genuine multi-rate invoice.
     *
     * @return list<array<string, mixed>>
     */
    private function extraItems(int $version): array
    {
        return [
            ['code' => 'PHYSIO-30', 'description' => 'Physiotherapy session, 30 minutes', 'unit_price_minor' => $version === 1 ? 3400 : 3500, 'vat_rate_bp' => 810, 'unit' => 'session', 'requires_service_documentation' => false, 'active' => true],
            ['code' => 'LAB-PANEL', 'description' => 'Laboratory panel', 'unit_price_minor' => $version === 1 ? 2500 : 2600, 'vat_rate_bp' => 1900, 'unit' => 'panel', 'requires_service_documentation' => false, 'active' => true],
            ['code' => 'DUNNING-FEE', 'description' => 'Dunning fee, level 1', 'unit_price_minor' => 1500, 'vat_rate_bp' => 0, 'unit' => 'item', 'requires_service_documentation' => false, 'active' => true],
        ];
    }

    // -----------------------------------------------------------------
    // Patients
    // -----------------------------------------------------------------

    private function seedPatients(): void
    {
        $this->seedConsentTemplates();

        $curated = [
            ['Erika', 'Baumgartner', '1954-03-12', 'female', 'de'],
            ['Viktor', 'Odermatt', '1968-11-02', 'male', 'de'],
            ['Nadia', 'Lüthi', '1941-07-25', 'female', 'de'],
            ['Beatrice', 'Weber', '1979-01-30', 'female', 'fr'],
            ['Jonas', 'Gerber', '1993-05-18', 'male', 'de'],
            ['Chantal', 'Pfister', '1986-09-09', 'female', 'fr'],
            ['Reto', 'Zimmermann', '1957-12-21', 'male', 'de'],
            ['Silvia', 'Meier', '1972-06-14', 'female', 'de'],
            ['Marco', 'Iten', '2001-02-27', 'male', 'it'],
            ['Regula', 'Tanner', '1949-08-05', 'female', 'de'],
            ['Kilian', 'Roth', '1965-04-11', 'male', 'de'],
            ['Ladina', 'Egli', '1998-10-03', 'female', 'de'],
            ['Bruno', 'Nussbaumer', '1938-02-16', 'male', 'de'],
        ];

        $patientService = app(PatientService::class);

        foreach ($curated as $index => [$firstName, $lastName, $dob, $sex, $language]) {
            $this->patients[] = $patientService->create(
                [
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'date_of_birth' => $dob,
                    'sex' => $sex,
                    'preferred_language' => $language,
                    'status' => Patient::STATUS_ACTIVE,
                ],
                $this->contactsFor($firstName, $lastName, $index),
                $index % 3 === 0 ? [['system' => 'ahv', 'value' => '756.'.sprintf('%04d.%04d.%02d', 1000 + $index, 2000 + $index, 10 + $index)]] : [],
                $index % 2 === 0 ? [[
                    'payer_name' => $index % 4 === 0 ? 'Helvetia Versicherung' : 'CSS Versicherung',
                    'member_id' => 'M'.sprintf('%08d', 74_100_000 + $index),
                    'plan' => $index % 4 === 0 ? 'Standard' : 'Komfort',
                    'coverage_type' => 'private_insurance',
                    'priority' => 1,
                ]] : [],
            );
        }

        $this->seedNearDuplicates($patientService);
        $this->seedConsentsAndPortal();
    }

    /**
     * Two deliberate near-duplicates: the same person entered twice, once as a
     * nickname and once with a transposed spelling. This is what the dedup
     * review screen exists to catch — nothing here merges them.
     */
    private function seedNearDuplicates(PatientService $patientService): void
    {
        $silvia = $this->patients[7];
        $this->patients[] = $patientService->create(
            PatientFactory::new()->nearDuplicateOf($silvia, 'Sylvia')->raw(),
            [['type' => PatientContact::TYPE_PHONE, 'value' => '+41 44 271 88 13', 'is_primary' => true]],
        );

        $kilian = $this->patients[10];
        $this->patients[] = $patientService->create(
            PatientFactory::new()->nearDuplicateOf($kilian, 'Killian')->raw(),
            [['type' => PatientContact::TYPE_PHONE, 'value' => '+41 44 362 04 55', 'is_primary' => true]],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function contactsFor(string $firstName, string $lastName, int $index): array
    {
        $streets = ['Universitätstrasse', 'Winterthurerstrasse', 'Schaffhauserstrasse', 'Ottikerstrasse', 'Scheuchzerstrasse'];

        return [
            [
                'type' => PatientContact::TYPE_EMAIL,
                'value' => Str::slug($firstName.' '.$lastName, '.').'@example.test',
                'is_primary' => true,
            ],
            [
                'type' => PatientContact::TYPE_PHONE,
                'value' => sprintf('+41 44 %03d %02d %02d', 200 + $index, 10 + $index, 20 + $index),
                'is_primary' => true,
            ],
            [
                'type' => PatientContact::TYPE_ADDRESS,
                'line1' => $streets[$index % count($streets)].' '.(4 + $index * 3),
                'city' => 'Zürich',
                'postal' => (string) (8006 + ($index % 3)),
                'country' => 'CH',
                'is_primary' => true,
            ],
        ];
    }

    private function seedConsentTemplates(): void
    {
        ConsentTemplate::query()->create([
            'key' => 'portal',
            'title' => 'Patientenportal — Zugang',
            'body' => 'I consent to accessing my health record, documents, and invoices through the Praxis Lindenhof patient portal.',
            'version' => 1,
            'scope_keys' => ['portal.access'],
            'is_active' => true,
        ]);

        ConsentTemplate::query()->create([
            'key' => 'comms',
            'title' => 'Kommunikation per E-Mail',
            'body' => 'I consent to Praxis Lindenhof contacting me by email about my appointments and care.',
            'version' => 1,
            'scope_keys' => ['comms.email'],
            'is_active' => true,
        ]);
    }

    /**
     * Portal and email consent for some patients, not all — the fail-closed
     * consent gate is only interesting when somebody has not granted it.
     */
    private function seedConsentsAndPortal(): void
    {
        $consents = app(ConsentService::class);
        $capturer = $this->users['reception'];

        foreach ([0, 1, 2, 3, 4, 6, 7] as $index) {
            $patient = $this->patients[$index];
            $consents->grant($patient, 'comms', $patient->first_name.' '.$patient->last_name, $capturer);
        }

        foreach ([0, 1, 2, 3] as $index) {
            $patient = $this->patients[$index];
            $consents->grant($patient, 'portal', $patient->first_name.' '.$patient->last_name, $capturer);
        }

        // Two live portal accounts, activated through the real invite path.
        $portal = app(PortalAccessService::class);

        foreach ([0, 2] as $index) {
            $patient = $this->patients[$index];
            $invite = $portal->invite($patient, Str::slug($patient->first_name.' '.$patient->last_name, '.').'@example.test');
            $portal->acceptInvite($invite->plainToken, $invite->otp, self::PORTAL_PASSWORD);
        }
    }

    // -----------------------------------------------------------------
    // Scheduling: the current week
    // -----------------------------------------------------------------

    /**
     * A week the reception day-board can actually show: every lifecycle state
     * reachable through the real transitions, two online self-bookings, and a
     * waitlist entry waiting for a cancellation to free a slot.
     */
    private function seedAppointments(): void
    {
        $booking = app(BookingService::class);
        $lifecycle = app(AppointmentService::class);
        $reception = $this->users['reception'];

        $book = fn (string $serviceKey, int $patientIndex, CarbonImmutable $startsAt, array $resourceKeys) => $booking->book(
            $this->services[$serviceKey]->id,
            $this->patients[$patientIndex]->id,
            $this->branch->id,
            $startsAt,
            array_map(fn (string $key): string => $this->resources[$key]->id, $resourceKeys),
            $reception,
        );

        // booked — left alone
        $book('consult', 4, $this->weekday(1)->setTime(9, 0), ['doctor_brunner', 'room_1']);
        $book('follow_up', 5, $this->weekday(4)->setTime(11, 30), ['doctor_keller', 'room_2']);

        // confirmed
        $lifecycle->confirm($book('consult', 6, $this->weekday(2)->setTime(9, 0), ['doctor_brunner', 'room_1']), $reception);

        // arrived
        $arrived = $book('consult', 7, $this->weekday(2)->setTime(10, 0), ['doctor_brunner', 'room_1']);
        $lifecycle->arrive($lifecycle->confirm($arrived, $reception), $reception);

        // completed — each transition returns the moved appointment; the next
        // one has to act on that, not on the stale local.
        $completed = $book('follow_up', 8, $this->weekday(1)->setTime(14, 0), ['doctor_keller', 'room_2']);
        $completed = $lifecycle->confirm($completed, $reception);
        $completed = $lifecycle->arrive($completed, $reception);
        $completed = $lifecycle->start($completed, $reception);
        $lifecycle->complete($completed, $reception);

        // cancelled — frees the slot the waitlist entry below is waiting for
        $lifecycle->cancel(
            $book('consult', 9, $this->weekday(3)->setTime(9, 0), ['doctor_brunner', 'room_1']),
            $reception,
            'Patient ist erkrankt und sagt ab.',
        );

        // no_show
        $lifecycle->noShow(
            $book('follow_up', 10, $this->weekday(1)->setTime(15, 30), ['doctor_keller', 'room_2']),
            $reception,
            'Nicht erschienen, telefonisch nicht erreichbar.',
        );

        // rescheduled — the original closes, the replacement is booked
        $lifecycle->reschedule(
            $book('consult', 11, $this->weekday(2)->setTime(14, 0), ['doctor_brunner', 'room_1']),
            $this->weekday(4)->setTime(14, 0),
            [$this->resources['doctor_brunner']->id, $this->resources['room_1']->id],
            $reception,
            'Terminkonflikt beim Patienten.',
        );

        // in_progress is reached by opening the encounter, not by hand — see
        // seedClinical(), which documents the consult on this appointment.
        $this->appointmentInProgress = $book('consult', 0, $this->weekday(3)->setTime(10, 30), ['doctor_brunner', 'room_1']);

        // Two online self-bookings through the public path.
        $booking->bookOnline(
            $this->services['consult']->id,
            $this->patients[3]->id,
            $this->branch->id,
            $this->weekday(5)->setTime(9, 30),
            [$this->resources['doctor_keller']->id, $this->resources['room_2']->id],
            'Online gebucht über die Website.',
        );

        $booking->bookOnline(
            $this->services['telehealth']->id,
            $this->patients[5]->id,
            $this->branch->id,
            $this->weekday(5)->setTime(16, 0),
            [$this->resources['doctor_brunner']->id],
            'Videosprechstunde, online gebucht.',
        );

        app(WaitlistService::class)->create([
            'patient_id' => $this->patients[12]->id,
            'service_id' => $this->services['consult']->id,
            'branch_id' => $this->branch->id,
            'desired_starts_at' => $this->weekday(3)->setTime(8, 0)->toDateTimeString(),
            'desired_ends_at' => $this->weekday(3)->setTime(12, 0)->toDateTimeString(),
            'flexible' => true,
            'priority' => 10,
        ]);
    }

    // -----------------------------------------------------------------
    // Clinical
    // -----------------------------------------------------------------

    private function seedClinical(): void
    {
        $this->seedClinicalLists();
        $this->seedLiveConsult();
        $this->seedUnsignedDrafts();
        $this->seedDocuments();
        $this->seedReferralAndRecalls();
        $this->seedCarePlan();
    }

    /**
     * Problems, allergies, raw vitals, and documented medications. Nothing here
     * is interpreted, scored, or flagged — the record states what was observed.
     */
    private function seedClinicalLists(): void
    {
        $lists = app(ClinicalListService::class);
        $medications = app(MedicationService::class);
        $doctor = $this->staff['doctor_brunner'];
        $actor = $this->users['doctor_brunner'];

        $problems = [
            [0, 'Essential hypertension', 'I10', '2016-04-02'],
            [0, 'Type 2 diabetes mellitus', 'E11', '2019-09-14'],
            [1, 'Chronic low back pain', 'M54.5', '2021-02-08'],
            [2, 'Osteoarthritis of the knee', 'M17', '2015-06-19'],
            [2, 'Essential hypertension', 'I10', '2012-11-03'],
            [6, 'Asthma', 'J45', '2003-03-27'],
            [9, 'Atrial fibrillation', 'I48', '2020-01-16'],
            [12, 'Essential hypertension', 'I10', '2009-05-30'],
        ];

        foreach ($problems as [$patientIndex, $description, $code, $onset]) {
            $lists->recordProblem($this->patients[$patientIndex], $doctor, $actor, [
                'description' => $description,
                'code' => $code,
                'onset_date' => $onset,
            ]);
        }

        // At least one severe allergy, so the allergy banner has something loud
        // to show — and the deterministic hard-stop something to catch.
        $lists->recordAllergy($this->patients[0], $doctor, $actor, [
            'substance' => 'Penicillin',
            'reaction' => 'Anaphylaxis requiring adrenaline and admission.',
            'severity' => 'severe',
            'verified_at' => now()->subYears(2),
        ]);

        $lists->recordAllergy($this->patients[2], $doctor, $actor, [
            'substance' => 'Ibuprofen',
            'reaction' => 'Widespread urticaria.',
            'severity' => 'moderate',
        ]);

        $lists->recordAllergy($this->patients[6], $doctor, $actor, [
            'substance' => 'Pollen',
            'reaction' => 'Seasonal rhinitis.',
            'severity' => 'mild',
        ]);

        // Documented medications with the doses as prescribed elsewhere. CareOS
        // records what a prescriber decided; it never derives or suggests a dose.
        $prescriptions = [
            [0, 'Metformin', '850 mg', 'oral', 'twice daily with meals', '2019-09-20'],
            [0, 'Lisinopril', '10 mg', 'oral', 'once daily in the morning', '2016-04-10'],
            [1, 'Ibuprofen', '400 mg', 'oral', 'up to three times daily as needed', '2021-02-15'],
            [2, 'Amlodipine', '5 mg', 'oral', 'once daily', '2013-01-08'],
            [6, 'Salbutamol', '100 mcg per actuation', 'inhalation', 'two puffs as needed', '2003-04-02'],
            [9, 'Apixaban', '5 mg', 'oral', 'twice daily', '2020-01-24'],
        ];

        foreach ($prescriptions as [$patientIndex, $name, $dose, $route, $frequency, $startedOn]) {
            $medications->record($this->patients[$patientIndex], $doctor, $actor, [
                'name' => $name,
                'dose_text' => $dose,
                'route' => $route,
                'frequency_text' => $frequency,
                'started_on' => $startedOn,
            ]);
        }

        // Raw vitals across the demo period, tied to no interpretation at all.
        foreach ([0, 1, 2, 6, 9] as $patientIndex) {
            foreach ([3, 12, 24] as $dayOfMonth) {
                VitalFactory::new()
                    ->forPatient($this->patients[$patientIndex])
                    ->recordedBy($doctor)
                    ->recordedAt($this->day($dayOfMonth)->setTime(9, 20)->toDateTimeString())
                    ->create();
            }
        }
    }

    /**
     * The honest consult loop, run for real: the day-board appointment goes
     * in_progress by opening the encounter, the note is drafted, signed, and
     * then amended with a reason — so the chart shows both versions.
     */
    private function seedLiveConsult(): void
    {
        $encounters = app(EncounterService::class);
        $notes = app(ClinicalNoteService::class);
        $doctor = $this->staff['doctor_brunner'];
        $actor = $this->users['doctor_brunner'];

        $encounter = $encounters->open(
            $this->patients[0],
            $doctor,
            $this->branch,
            $this->appointmentInProgress,
            Encounter::TYPE_CONSULTATION,
            $actor,
            'Blutdruckkontrolle und Medikamentenüberprüfung.',
        );

        $draft = $notes->saveDraft($encounter, $doctor, [
            'subjective' => 'Patient reports occasional morning headaches over the past two weeks. Takes metformin and lisinopril as prescribed; no missed doses reported.',
            'objective' => 'Alert and oriented. Blood pressure recorded at 148/92 seated, repeated at 144/90 after five minutes. Heart rate 78 and regular. Chest clear on auscultation.',
            'assessment' => 'Hypertension, previously documented, above the target agreed at the last review. Diabetes management unchanged.',
            'plan' => 'Home blood pressure diary for two weeks. Review appointment booked. Continue current medication unchanged pending the diary.',
        ], $actor);

        $signed = $notes->sign($draft, $actor);

        // An amendment is born a draft and supersedes nothing until it is
        // signed; signing it is what gives the chart two real versions to show.
        $amendment = $notes->amend(
            $signed,
            ['objective' => 'Alert and oriented. Blood pressure recorded at 148/92 seated, repeated at 144/90 after five minutes. Heart rate 78 and regular. Chest clear on auscultation. Weight recorded at 82.4 kg.'],
            'Weight was measured during the consultation and omitted from the signed note in error.',
            $doctor,
            $actor,
        );
        $notes->sign($amendment, $actor);

        // A second signed consult, by the other doctor, so notes vary in voice.
        $second = $encounters->open(
            $this->patients[6],
            $this->staff['doctor_keller'],
            $this->branch,
            null,
            Encounter::TYPE_FOLLOW_UP,
            $this->users['doctor_keller'],
            'Asthmakontrolle.',
        );

        $notes->sign(
            $notes->saveDraft($second, $this->staff['doctor_keller'], [
                'subjective' => 'Reports two night-time awakenings with wheeze this month, both relieved by the reliever inhaler. No exacerbations, no oral steroids since the last review.',
                'objective' => 'Comfortable at rest. Chest clear. Inhaler technique observed and correct.',
                'assessment' => 'Asthma, previously documented, reliever use unchanged from the last review.',
                'plan' => 'Continue current inhalers. Written asthma action plan reissued. Review in six months, sooner if reliever use increases.',
            ], $this->users['doctor_keller']),
            $this->users['doctor_keller'],
        );

        $encounters->close($second, $this->users['doctor_keller']);
    }

    /**
     * Drafts old enough to appear on the aged unsigned-notes worklist. The
     * worklist filters on created_at, so the rows are aged explicitly — a draft
     * created this second would never show up on the screen being demoed.
     * Drafts are mutable by design; only signed notes are frozen.
     */
    private function seedUnsignedDrafts(): void
    {
        $notes = app(ClinicalNoteService::class);

        $drafts = [
            ['patient' => 1, 'staff' => 'doctor_brunner', 'days' => 6, 'subjective' => 'Reports the back pain is unchanged since the last review; analgesia taken as needed.'],
            ['patient' => 4, 'staff' => 'doctor_keller', 'days' => 3, 'subjective' => 'Attends for a routine check-up. No new concerns raised.'],
            ['patient' => 9, 'staff' => 'doctor_brunner', 'days' => 11, 'subjective' => 'Reports palpitations twice in the past fortnight, each lasting a few minutes and settling on its own.'],
        ];

        foreach ($drafts as $draft) {
            $staff = $this->staff[$draft['staff']];
            $actor = $this->users[$draft['staff']];
            $encounter = EncounterFactory::new()
                ->forPatient($this->patients[$draft['patient']])
                ->withPractitioner($staff)
                ->atBranch($this->branch)
                ->on(CarbonImmutable::today()->subDays($draft['days'])->toDateString(), '11:00:00')
                ->create();

            $note = $notes->saveDraft($encounter, $staff, [
                'subjective' => $draft['subjective'],
                'objective' => 'Examination findings to be completed.',
            ], $actor);

            $note->forceFill(['created_at' => CarbonImmutable::today()->subDays($draft['days'])->setTime(11, 30)])->save();
        }
    }

    private function seedDocuments(): void
    {
        $documents = app(DocumentService::class);
        $actor = $this->users['doctor_brunner'];

        $shared = $documents->upload(
            $this->patients[0],
            $actor,
            UploadedFile::fake()->createWithContent('laborbericht.txt', "Praxis Lindenhof — laboratory report\nDemo document. Not a real result.\n"),
            ['category' => 'result', 'title' => 'Laborbericht — Blutbild'],
        );
        $documents->shareWithPatient($shared, $actor);

        $documents->upload(
            $this->patients[2],
            $actor,
            UploadedFile::fake()->createWithContent('ueberweisung.txt', "Praxis Lindenhof — referral letter\nDemo document. Not a real letter.\n"),
            ['category' => 'letter', 'title' => 'Überweisung — Kardiologie'],
        );
    }

    private function seedReferralAndRecalls(): void
    {
        $referrals = app(ReferralService::class);
        $actor = $this->users['doctor_brunner'];

        $referral = $referrals->create($this->patients[9], $actor, [
            'direction' => Referral::DIRECTION_OUTBOUND,
            'to_provider_name' => 'Kardiologie Zentrum Zürich',
            'specialty' => 'cardiology',
            'reason' => 'Documented atrial fibrillation; requesting a cardiology opinion on rate control.',
        ]);
        $referrals->send($referral, $actor);

        // A deterministic recall rule: exact active problem-code membership.
        // No inference, no model, no scoring picks these patients.
        RecallRule::query()->create([
            'name' => 'Hypertonie — Jahreskontrolle',
            'criteria' => ['active_problem_codes' => ['I10']],
            'interval_months' => 12,
            'active' => true,
        ]);

        app(RecallEngine::class)->evaluate($this->tenant, $actor);
    }

    private function seedCarePlan(): void
    {
        $carePlans = app(CarePlanService::class);
        $tasks = app(ClinicalTaskService::class);
        $actor = $this->users['doctor_brunner'];

        $plan = $carePlans->create(
            $this->patients[2],
            $this->staff['doctor_brunner'],
            $actor,
            [
                'title' => 'Betreuungsplan — Mobilität und Blutdruck',
                'started_on' => $this->periodStart->toDateString(),
            ],
            [
                ['description' => 'Walk unaided to the letterbox and back once a day.', 'target_date' => $this->weekStart->addWeeks(6)->toDateString()],
                ['description' => 'Home blood pressure recorded three mornings a week.', 'target_date' => $this->weekStart->addWeeks(4)->toDateString()],
            ],
        );

        $carePlans->addGoal($plan, $actor, [
            'description' => 'Attend the physiotherapy sessions agreed in the plan.',
            'target_date' => $this->weekStart->addWeeks(8)->toDateString(),
        ]);

        $tasks->create($actor, $this->staff['nurse_frei'], [
            'patient_id' => $this->patients[2]->id,
            'care_plan_id' => $plan->id,
            'title' => 'Blutdruck-Tagebuch mit der Patientin durchgehen',
            'description' => 'Review the home blood pressure diary at the next home visit and record the readings.',
            'due_at' => $this->weekday(4)->setTime(12, 0)->toDateTimeString(),
            'priority' => 'normal',
        ]);

        $tasks->create($actor, $this->staff['coordinator'], [
            'patient_id' => $this->patients[2]->id,
            'title' => 'Folgetermin Physiotherapie vereinbaren',
            'due_at' => $this->weekday(5)->setTime(17, 0)->toDateTimeString(),
            'priority' => 'high',
        ]);
    }

    // -----------------------------------------------------------------
    // Nursing
    // -----------------------------------------------------------------

    private function seedNursing(): void
    {
        $agreements = app(ServiceAgreementService::class);
        $coordinator = $this->users['coordinator'];

        // Nadia Lüthi — the fully worked home-care chain that ends up invoiced.
        $nadia = $agreements->create([
            'patient_id' => $this->patients[2]->id,
            'branch_id' => $this->branch->id,
            'funding_type' => ServiceAgreement::FUNDING_PRIVATE_INSURANCE,
            'payer_name' => 'CSS Versicherung',
            'authorization_ref' => 'AUTH-2026-004182',
            'authorized_hours_per_week' => 3.00,
            'starts_on' => $this->periodStart->toDateString(),
            'status' => ServiceAgreement::STATUS_DRAFT,
        ], [[
            'service_id' => $this->services['home']->id,
            'planned_frequency_text' => 'Montag, Mittwoch und Freitag vormittags',
            'required_qualification' => 'rn',
            'duration_minutes' => 60,
        ]], $coordinator);
        $agreements->activate($nadia, $coordinator);

        // Bruno Nussbaumer — assigned work in the week ahead, nothing executed.
        $bruno = $agreements->create([
            'patient_id' => $this->patients[12]->id,
            'branch_id' => $this->branch->id,
            'funding_type' => ServiceAgreement::FUNDING_SELF_PAY,
            'authorized_hours_per_week' => 2.00,
            'starts_on' => $this->weekStart->toDateString(),
            'status' => ServiceAgreement::STATUS_DRAFT,
        ], [[
            'service_id' => $this->services['home']->id,
            'planned_frequency_text' => 'Dienstag und Donnerstag vormittags',
            'required_qualification' => 'rn',
            'duration_minutes' => 60,
        ]], $coordinator);
        $agreements->activate($bruno, $coordinator);

        $nadiaVisits = $this->materializePlan($nadia, 'FREQ=WEEKLY;BYDAY=MO,WE,FR', '09:00:00', '10:30:00', $this->periodStart);
        $brunoVisits = $this->materializePlan($bruno, 'FREQ=WEEKLY;BYDAY=TU,TH', '10:00:00', '11:30:00', $this->weekStart);

        $this->assignVisits($nadiaVisits, 'nurse_frei');
        $this->assignVisits($brunoVisits, 'nurse_bianchi');

        $this->executeVisits($nadia);
        $this->seedTimesheets();
        $this->seedSyncConflict();
    }

    /**
     * @return list<PlannedVisit>
     */
    private function materializePlan(
        ServiceAgreement $agreement,
        string $rrule,
        string $windowStart,
        string $windowEnd,
        CarbonImmutable $startsOn,
    ): array {
        $agreementService = $agreement->agreementServices()->firstOrFail();

        $plan = VisitPlan::query()->create([
            'service_agreement_id' => $agreement->id,
            'agreement_service_id' => $agreementService->id,
            'rrule' => $rrule,
            'timezone' => self::TIMEZONE,
            'window_start_time' => $windowStart,
            'window_end_time' => $windowEnd,
            'duration_minutes' => 60,
            'starts_on' => $startsOn->toDateString(),
            'active' => true,
        ]);

        app(VisitPlanGenerator::class)->materialize($plan, $startsOn, $this->weekStart->addDays(6));

        $visits = PlannedVisit::query()
            ->where('visit_plan_id', $plan->id)
            ->orderBy('scheduled_date')
            ->get()
            ->all();

        // Straight-line coordinates for the deterministic travel check. Real
        // road routing is deferred; this is the documented D-E3 stand-in.
        foreach ($visits as $index => $visit) {
            $visit->forceFill([
                'location_latitude' => 47.3900 + ($index % 5) * 0.004,
                'location_longitude' => 8.5450 + ($index % 5) * 0.003,
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
     * The previous month's visits are worked end to end: check-in, care notes,
     * raw vitals, check-out — the proof rows a timesheet and an invoice hang off.
     * This week's visits stay assigned, so the dispatcher board has work on it.
     */
    private function executeVisits(ServiceAgreement $agreement): void
    {
        $visitService = app(VisitService::class);
        $nurse = $this->users['nurse_frei'];
        $nurseResource = $this->resources['nurse_frei'];

        $planned = PlannedVisit::query()
            ->whereIn('visit_plan_id', VisitPlan::query()->where('service_agreement_id', $agreement->id)->pluck('id'))
            ->whereDate('scheduled_date', '<=', $this->day(27)->toDateString())
            ->orderBy('scheduled_date')
            ->get();

        foreach ($planned as $index => $plannedVisit) {
            $visit = $visitService->createFromPlannedVisit($plannedVisit, 'demo-'.strtolower((string) Str::ulid()));

            $arrival = $plannedVisit->window_start_at->copy()->addMinutes(3 + ($index % 7));
            $departure = $arrival->copy()->addMinutes(58 + ($index % 5));

            // Most check-ins are GPS at the door; one is a documented manual
            // fallback, which is exactly what the timesheet flags for review.
            $location = $index === 2
                ? 'Kein GPS-Signal im Treppenhaus; Ankunft manuell erfasst.'
                : [
                    'latitude' => (float) $plannedVisit->location_latitude,
                    'longitude' => (float) $plannedVisit->location_longitude,
                    'accuracy_meters' => 8.5,
                ];

            $visitService->checkIn($visit, $nurse, $location, $arrival);

            VisitVital::query()->create([
                'visit_id' => $visit->id,
                'patient_id' => $visit->patient_id,
                'recorded_at' => $arrival->copy()->addMinutes(6),
                'systolic' => 128 + ($index % 9),
                'diastolic' => 76 + ($index % 5),
                'heart_rate' => 68 + ($index % 8),
                'temperature_c' => 36.4 + ($index % 4) / 10,
                'spo2' => 96 + ($index % 3),
            ]);

            VisitNote::query()->create([
                'visit_id' => $visit->id,
                'patient_id' => $visit->patient_id,
                'body' => $this->visitNoteBody($index),
                'author_resource_id' => $nurseResource->id,
                'recorded_at' => $departure->copy()->subMinutes(4),
            ]);

            $visitService->checkOut($visit, $nurse, [
                'latitude' => (float) $plannedVisit->location_latitude,
                'longitude' => (float) $plannedVisit->location_longitude,
                'accuracy_meters' => 9.0,
            ], $departure);

            $this->completedVisits[] = $visit->refresh();
        }
    }

    private function visitNoteBody(int $index): string
    {
        $bodies = [
            'Support with washing and dressing. Ate breakfast independently. No concerns raised.',
            'Compression stockings applied. Walked to the kitchen and back with the frame.',
            'Medication prompt given as documented on the plan. Blood pressure recorded in the diary.',
            'Wound dressing changed as scheduled. Dressing intact and dry on removal.',
            'Support with washing. Daughter present for part of the visit.',
        ];

        return $bodies[$index % count($bodies)];
    }

    private function seedTimesheets(): void
    {
        $timesheets = app(TimesheetService::class);

        $lines = $timesheets->generateFromVisits(
            $this->resources['nurse_frei'],
            $this->periodStart,
            $this->day(27),
        );

        // The coordinator approves the clean lines; anything the service flagged
        // for review is deliberately left in draft for a human to look at.
        foreach ($lines as $line) {
            if ($line->discrepancy_flags === null || $line->discrepancy_flags === []) {
                $timesheets->approve($line, $this->users['coordinator']);
            }
        }
    }

    /**
     * One D-E1 conflict left open for review: the nurse's device replayed a
     * note for a visit whose schedule had already moved on the server.
     */
    private function seedSyncConflict(): void
    {
        $visit = $this->completedVisits[0] ?? null;

        if (! $visit instanceof Visit) {
            return;
        }

        SyncConflict::query()->create([
            'visit_id' => $visit->id,
            'nurse_resource_id' => $this->resources['nurse_frei']->id,
            'action_type' => 'visit.note',
            'client_payload' => [
                'client_action_uuid' => (string) Str::uuid(),
                'body' => 'Support with washing and dressing; no concerns raised.',
                'device_timestamp' => $visit->checked_out_at?->toIso8601String(),
            ],
            'server_state' => [
                'visit_status' => $visit->status,
                'checked_out_at' => $visit->checked_out_at?->toIso8601String(),
            ],
            'reason' => 'schedule_changed_while_offline',
            'status' => SyncConflict::STATUS_OPEN,
        ]);
    }

    // -----------------------------------------------------------------
    // Billing: a closed month that reconciles to the unit
    // -----------------------------------------------------------------

    private function seedBilling(): void
    {
        $capture = app(ChargeCaptureService::class);
        $validator = app(ChargeValidator::class);
        $issuer = app(IssueService::class);
        $payments = app(PaymentService::class);
        $actor = $this->users['billing'];

        $erika = $this->patients[0];
        $viktor = $this->patients[1];
        $nadia = $this->patients[2];

        // --- encounter-based charges, both sides of the tariff boundary ---
        $erikaFirst = $this->encounterCharges($capture, $erika, 'doctor_brunner', $actor, [
            3 => ['CONSULT-30', 'LAB-PANEL'],
            8 => ['CONSULT-30', 'PHYSIO-30'],
            12 => ['CONSULT-30', 'LAB-PANEL', 'PHYSIO-30'],
        ]);
        $erikaSecond = $this->encounterCharges($capture, $erika, 'doctor_brunner', $actor, [
            18 => ['CONSULT-30', 'PHYSIO-30'],
            23 => ['CONSULT-30', 'LAB-PANEL'],
        ]);

        $viktorFirst = $this->encounterCharges($capture, $viktor, 'doctor_keller', $actor, [
            4 => ['CONSULT-30', 'LAB-PANEL'],
            9 => ['CONSULT-30'],
        ]);
        $viktorSecond = $this->encounterCharges($capture, $viktor, 'doctor_keller', $actor, [
            24 => ['CONSULT-30', 'LAB-PANEL'],
        ]);

        // A quantity-2 line, so the credit note below can be a true partial.
        $physioEncounter = $this->billableEncounter($viktor, 'doctor_keller', $this->day(17));
        $viktorSecond[] = $capture->captureFromEncounter($physioEncounter, 'PHYSIO-30', 2, $actor);

        // --- visit-based charges, from the visits actually worked ---
        $nadiaFirst = [];
        $nadiaSecond = [];

        foreach ($this->completedVisits as $visit) {
            $bucket = $visit->scheduled_start_at->lessThanOrEqualTo($this->boundary->endOfDay())
                ? 'first'
                : 'second';

            $charges = [
                $capture->captureFromVisit($visit, 'HOME-60', 1, $actor),
                $capture->captureFromVisit($visit, 'TRAVEL-15', 1, $actor),
            ];

            if ($bucket === 'first') {
                $nadiaFirst = [...$nadiaFirst, ...$charges];
            } else {
                $nadiaSecond = [...$nadiaSecond, ...$charges];
            }
        }

        // --- validate everything before a single invoice is drafted ---
        foreach ([$erika, $viktor, $nadia] as $patient) {
            $result = $validator->validateForPatientPeriod(
                $patient,
                $this->periodStart->toDateString(),
                $this->periodStart->endOfMonth()->toDateString(),
                $actor,
            );

            if ($result['violations'] !== []) {
                throw new RuntimeException('Demo charges must validate cleanly before invoicing.');
            }
        }

        // --- six invoices, issued in order => numbers 1..6, no gaps ---
        // INV-1 is due on day 13 so it reaches dunning level 1 (+14) on day 27.
        $invoices = [
            1 => $this->issue($issuer, $erika, $erikaFirst, $actor, 13, 13),
            2 => $this->issue($issuer, $viktor, $viktorFirst, $actor, 14, 28),
            3 => $this->issue($issuer, $nadia, $nadiaFirst, $actor, 14, 28),
            4 => $this->issue($issuer, $erika, $erikaSecond, $actor, 26, 28),
            5 => $this->issue($issuer, $viktor, $viktorSecond, $actor, 26, 28),
            6 => $this->issue($issuer, $nadia, $nadiaSecond, $actor, 26, 28),
        ];

        // --- payments: full, partial, an overpayment, and a reversal ---
        $full = $payments->record($invoices[2]->total_minor, Payment::METHOD_BANK_TRANSFER, $actor, $viktor, 'Viktor Odermatt', null, $this->day(18)->toDateString());
        $payments->allocate($full, $invoices[2], $invoices[2]->total_minor, $actor);

        $half = intdiv($invoices[3]->total_minor, 2);
        $partial = $payments->record($half, Payment::METHOD_BANK_TRANSFER, $actor, $nadia, 'CSS Versicherung', null, $this->day(20)->toDateString());
        $payments->allocate($partial, $invoices[3], $half, $actor);

        // The overpayment remainder stays visibly unallocated — never guessed at.
        $over = $payments->record($invoices[4]->total_minor + 2500, Payment::METHOD_CARD, $actor, $erika, 'Erika Baumgartner', null, $this->day(27)->toDateString());
        $payments->allocate($over, $invoices[4], $invoices[4]->total_minor, $actor);

        // A misallocation, corrected the only legitimate way: a reversal ROW.
        $misdirected = $payments->record($invoices[6]->total_minor, Payment::METHOD_BANK_TRANSFER, $actor, $nadia, 'CSS Versicherung', null, $this->day(27)->toDateString());
        $mistake = $payments->allocate($misdirected, $invoices[5], 1000, $actor);
        $payments->reverseAllocation($mistake, 'Auf die falsche Rechnung gebucht.', $actor);
        $payments->allocate($misdirected, $invoices[6], $invoices[6]->total_minor, $actor);

        // --- a partial credit note against INV-5 ---
        // creditNote() stamps issue_date = today and takes no date argument, so
        // this correction is dated now, against last month's invoice — which is
        // exactly how a real clinic credits a closed month. It therefore sits
        // outside the reconciled period by design; the original is untouched.
        $creditedLine = $invoices[5]->lines()->where('quantity', 2)->firstOrFail();
        $issuer->creditNote($invoices[5]->refresh(), [[
            'invoice_line_id' => $creditedLine->id,
            'quantity' => 1,
        ]], 'Eine Sitzung wurde nicht erbracht.', $actor);

        $this->seedDunning($actor);
    }

    /**
     * INV-1 (due day 13, unpaid) crosses level 1 on day 27. The level fee is a
     * NEW draft charge captured through the real path; the issued invoice is
     * never touched.
     */
    private function seedDunning(User $actor): void
    {
        app(SettingsService::class)->set(DunningService::SETTINGS_KEY, [
            'channel' => 'email',
            'levels' => [
                [
                    'level' => 1,
                    'days_past_due' => 14,
                    'template' => 'Ihre Rechnung ist überfällig. Bitte veranlassen Sie die Zahlung.',
                    'fee_code' => 'DUNNING-FEE',
                ],
                [
                    'level' => 2,
                    'days_past_due' => 30,
                    'template' => 'Zweite Mahnung. Bitte veranlassen Sie die Zahlung umgehend.',
                ],
            ],
        ], 'array');

        $events = app(DunningService::class)->evaluate($this->tenant, $this->day(27)->toDateString(), $actor, deliver: false);

        if (count($events) !== 1 || $events[0]->level !== 1) {
            throw new RuntimeException('The demo month expects exactly one level-1 dunning event.');
        }
    }

    /**
     * A closed encounter with a signed note — the documentation the tariff's
     * `requires_service_documentation` check looks for.
     */
    private function billableEncounter(Patient $patient, string $doctorKey, CarbonImmutable $date): Encounter
    {
        $staff = $this->staff[$doctorKey];

        $encounter = EncounterFactory::new()
            ->forPatient($patient)
            ->withPractitioner($staff)
            ->atBranch($this->branch)
            ->on($date->toDateString(), '09:00:00')
            ->create();

        // Historical notes are written signed and dated to the encounter: the
        // signing service would stamp signed_at = now(), which would date last
        // month's consult to today.
        ClinicalNote::query()->create([
            'encounter_id' => $encounter->id,
            'patient_id' => $patient->id,
            'author_id' => $staff->id,
            'subjective' => 'Attended as scheduled. Reports no new concerns since the last review.',
            'objective' => 'Examination performed and findings documented.',
            'assessment' => 'Previously documented conditions, unchanged from the last review.',
            'plan' => 'Services delivered as documented. Review as agreed.',
            'status' => ClinicalNote::STATUS_SIGNED,
            'signed_at' => $date->setTime(9, 40),
            'signed_by' => $staff->user_id,
            'version' => 1,
        ]);

        return $encounter;
    }

    /**
     * @param  array<int, list<string>>  $plan  day of month => codes
     * @return list<Charge>
     */
    private function encounterCharges(
        ChargeCaptureService $capture,
        Patient $patient,
        string $doctorKey,
        User $actor,
        array $plan,
    ): array {
        $charges = [];

        foreach ($plan as $dayOfMonth => $codes) {
            $encounter = $this->billableEncounter($patient, $doctorKey, $this->day($dayOfMonth));

            foreach ($codes as $code) {
                $charges[] = $capture->captureFromEncounter($encounter, $code, 1, $actor);
            }
        }

        return $charges;
    }

    /**
     * @param  list<Charge>  $charges
     */
    private function issue(
        IssueService $issuer,
        Patient $patient,
        array $charges,
        User $actor,
        int $issueDay,
        int $dueDay,
    ): Invoice {
        $draft = $issuer->createDraftFromCharges(
            $patient,
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
        $reception = $this->users['reception'];

        // Patient threads are built around the two patients who hold portal
        // accounts: only an active portal account may post as the patient.
        $erika = $this->patients[0];
        $nadia = $this->patients[2];

        // An ordinary admin question, answered.
        $admin = $threads->openPatientThread($erika, 'Frage zum Termin nächste Woche', $reception);
        $threads->addPatientParticipant($admin, $erika, $reception);
        $threads->postPatientMessage($admin, $erika, 'Guten Tag, kann ich meinen Termin am Mittwoch auf den Nachmittag verschieben?');
        $threads->postStaffMessage($admin, $reception, 'Guten Tag Frau Baumgartner, gerne. Am Mittwoch ist 16:00 Uhr frei — passt Ihnen das?');

        // An unanswered thread: assigned, unread, and the one the inbox agent
        // has drafted a reply for.
        $this->draftThread = $threads->openPatientThread($nadia, 'Bestätigung für die Versicherung', $reception);
        $threads->addPatientParticipant($this->draftThread, $nadia, $reception);
        $threads->postPatientMessage($this->draftThread, $nadia, 'Guten Tag, ich benötige eine Bestätigung meines nächsten Termins für meine Versicherung. Können Sie mir diese zusenden?');
        $threads->assign($this->draftThread, $reception, $reception);

        // A clinical question: flagged for a clinician, never answered by the
        // front desk and never drafted by the agent (D-065).
        $clinical = $threads->openPatientThread($erika, 'Frage zu meinen Medikamenten', $reception);
        $threads->addPatientParticipant($clinical, $erika, $reception);
        $threads->postPatientMessage($clinical, $erika, 'Guten Tag, mir ist seit gestern schwindlig. Soll ich meine Tabletten trotzdem nehmen?');
        $clinical->forceFill([
            'clinician_attention_at' => now(),
            'clinician_attention_reason' => 'clinical_question_requires_clinician',
        ])->save();

        // An internal thread — no patient may ever be referenced on one.
        // Both participants hold comms.manage (org_admin and reception do; the
        // coordinator deliberately does not).
        $internal = $threads->openInternalThread('Sprechzimmer 2 nächste Woche', $this->users['admin']);
        $threads->addStaffParticipant($internal, $reception, $this->users['admin']);
        $threads->postStaffMessage($internal, $this->users['admin'], 'Sprechzimmer 2 ist am Freitag wegen der Wartung nicht nutzbar — bitte keine Termine dort einplanen.');
        $threads->postStaffMessage($internal, $reception, 'Notiert, ich habe den Freitagnachmittag auf Sprechzimmer 1 gelegt.');

        $this->seedNotificationDeliveries();
    }

    /**
     * Two deliveries through the one notification engine: one sent to a patient
     * who granted email consent, one recorded as skipped for a patient who did
     * not. The consent matrix is fail-closed and the skip is evidence, not a gap.
     */
    private function seedNotificationDeliveries(): void
    {
        $notifications = app(NotificationService::class);

        $notifications->send('appointment.reminder', $this->patients[0], [
            'starts_at' => $this->weekday(3)->setTime(10, 30)->format('d.m.Y H:i'),
        ]);

        $notifications->send('appointment.reminder', $this->patients[8], [
            'starts_at' => $this->weekday(5)->setTime(9, 30)->format('d.m.Y H:i'),
        ]);
    }

    // -----------------------------------------------------------------
    // AiCore
    // -----------------------------------------------------------------

    /**
     * Two pending approval-queue items, so the AI surfaces render with content
     * — and neither has done anything. A proposal assigns nothing and a draft
     * posts nothing until a human approves it.
     */
    private function seedAiCore(): void
    {
        $this->seedKbArticles();

        $queue = app(ApprovalQueue::class);

        // A waitlist proposal for the slot the cancellation freed.
        $queue->propose(
            'scheduler.fill_from_waitlist',
            [
                'service_id' => $this->services['consult']->id,
                'branch_id' => $this->branch->id,
                'starts_at' => $this->weekday(3)->setTime(9, 0)->toDateTimeString(),
                'ends_at' => $this->weekday(3)->setTime(9, 30)->toDateTimeString(),
                'resource_ids' => [$this->resources['doctor_brunner']->id, $this->resources['room_1']->id],
            ],
            $this->users['reception'],
            'scheduler.fill_waitlist',
            'scheduler',
            'Ein Termin am Mittwoch 09:00 wurde abgesagt; auf der Warteliste steht eine passende Anfrage.',
            AutonomyPolicy::APPROVE,
        );

        // An inbox draft for the admin question — grounded, and waiting for a
        // human to read it and decide whether to send.
        $queue->propose(
            'comms.draft_reply',
            ['thread_id' => $this->draftThread?->id],
            $this->users['reception'],
            'comms.draft_reply',
            'inbox',
            'Eine Patientenanfrage im Posteingang ist unbeantwortet; ein Entwurf liegt zur Prüfung bereit.',
            AutonomyPolicy::SUGGEST,
        );
    }

    private function seedKbArticles(): void
    {
        KbArticle::query()->create([
            'title' => 'Öffnungszeiten und Erreichbarkeit',
            'body' => "Praxis Lindenhof, Zürich Oberstrass.\n\nOpening hours: Monday to Friday, 08:00 to 18:00. The practice is closed at weekends and on public holidays.\n\nOutside opening hours, please call the emergency number published on the practice website. In an emergency, call 144.",
            'tags' => ['opening-hours', 'contact'],
            'is_active' => true,
        ]);

        KbArticle::query()->create([
            'title' => 'Termine absagen und verschieben',
            'body' => "Appointments can be cancelled or moved through the patient portal or by calling reception during opening hours.\n\nPlease give at least 24 hours' notice so the slot can be offered to somebody on the waiting list. Appointments cancelled with less than 24 hours' notice may be charged.",
            'tags' => ['appointments', 'cancellation'],
            'is_active' => true,
        ]);

        KbArticle::query()->create([
            'title' => 'Rechnungen und Zahlung',
            'body' => "Invoices are issued at the end of each month and are visible in the patient portal.\n\nPayment is due within 14 days of the invoice date. Bank details are printed on the invoice. If you cannot pay within that period, contact reception to agree an arrangement.",
            'tags' => ['billing', 'invoices'],
            'is_active' => true,
        ]);
    }
}
