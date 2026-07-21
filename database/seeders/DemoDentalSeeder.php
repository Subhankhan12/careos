<?php

namespace Database\Seeders;

use Carbon\CarbonImmutable;
use Database\Factories\StaffProfileFactory;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Modules\Billing\Models\Charge;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;
use Modules\Billing\Services\ChargeValidator;
use Modules\Billing\Services\IssueService;
use Modules\Billing\Services\PaymentService;
use Modules\Dental\Models\DentalProcedure;
use Modules\Dental\Services\DentalCatalogService;
use Modules\Dental\Services\DentalChargeService;
use Modules\Dental\Services\DentalImagingService;
use Modules\Dental\Services\DiagnosisService;
use Modules\Dental\Services\PerformProcedureService;
use Modules\Dental\Services\PerioChartService;
use Modules\Dental\Services\ToothChartService;
use Modules\Dental\Services\TreatmentPlanService;
use Modules\Patients\Models\ConsentTemplate;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PatientContact;
use Modules\Patients\Services\ConsentService;
use Modules\Patients\Services\PatientService;
use Modules\Patients\Services\PortalAccessService;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Plan;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\SettingsService;
use Modules\Platform\Services\TenantContext;
use RuntimeException;

/**
 * Zahnarztpraxis Morgenstern — a companion demo tenant that shows a realistic GENERAL
 * DENTAL practice (DENTAL.G9), so a fresh seed no longer opens onto an empty dental
 * surface. It sits alongside {@see DemoClinicSeeder} and {@see DemoSpitexSeeder} and is
 * built the same way: through the REAL services, idempotent by slug, business dates passed
 * explicitly (never rewinding `now()`).
 *
 * Everything is invented — Swiss/EU names that belong to nobody, plausible dental work, a
 * tenant-authored fee schedule (the generic starter template — NO CDT codes are bundled).
 * No real patient data enters this file.
 *
 * Shape of the demo:
 *   - The LIVE dental surface (odontograms with a real correction history, a performed
 *     procedure, two treatment plans, two perio exams that trend, diagnoses, an image + a
 *     dentist's reading) sits in the current week — whoever opens the demo sees a practice
 *     mid-flow.
 *   - Dental BILLING sits in the previous full calendar month: procedures charged through
 *     the EXISTING engine → validated → six-gapless-nothing, three invoices, a full + a
 *     partial payment — a closed month that RECONCILES TO THE UNIT (the paired test proves
 *     all six invariants at delta_minor === 0).
 *
 * Fence: nothing here is graded, scored, staged, flagged, or AI-derived — the seeder only
 * records dentist-authored facts, exactly like the app does. No partner-gated feature is
 * implied (no live capture, no DICOM, no AI findings).
 *
 * Idempotent: keyed on the tenant slug — a second run does nothing at all.
 *
 * Run it with:
 *   php artisan db:seed --class=DemoDentalSeeder
 */
class DemoDentalSeeder extends Seeder
{
    public const TENANT_SLUG = 'zahnarztpraxis-morgenstern';

    public const TENANT_NAME = 'Zahnarztpraxis Morgenstern';

    public const BRANCH_NAME = 'Zürich Enge';

    public const TIMEZONE = 'Europe/Zurich';

    public const CURRENCY = 'CHF';

    public const STAFF_PASSWORD = 'demo-password';

    public const PORTAL_PASSWORD = 'demo-portal-password';

    /** The billing user's email — the reconciliation actor (see the paired test). */
    public const BILLING_EMAIL = 'thomas.graf@zahnarztpraxis-morgenstern.test';

    private Tenant $tenant;

    private Branch $branch;

    /** @var array<string, User> */
    private array $users = [];

    /** @var array<string, Patient> */
    private array $patients = [];

    /** @var array<string, DentalProcedure> */
    private array $procedures = [];

    private CarbonImmutable $periodStart;

    private CarbonImmutable $weekStart;

    /** The billing period the demo reconciles: the previous full calendar month. */
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
        $this->weekStart = CarbonImmutable::today()->startOfWeek();

        $this->provisionTenant();
        $this->seedStaff();
        $this->seedCatalog();
        $this->seedPatients();
        $this->seedLiveClinical();
        $this->seedBilling();
    }

    /** Day `$n` of the demo billing period. */
    private function day(int $n): CarbonImmutable
    {
        return $this->periodStart->addDays($n - 1);
    }

    /** Day `$n` (1 = start of week) of the current week. */
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

        // Tenant::created fires RbacProvisioner::provisionTenant() (system mode), seeding
        // the starter roles without polluting the tenant's audit chain.
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
            'code' => 'ZH-ENG',
            'timezone' => self::TIMEZONE,
        ]);
    }

    private function seedStaff(): void
    {
        $people = [
            // org_admin = the dentist-owner: holds BOTH dental.chart AND billing.manage, so
            // perform-a-procedure (which needs the intersection) runs as this actor.
            ['key' => 'owner', 'role' => 'org_admin', 'first' => 'Sabine', 'last' => 'Morgenstern', 'profession' => 'doctor', 'display' => 'Dr. med. dent. Sabine Morgenstern'],
            ['key' => 'dentist', 'role' => 'doctor', 'first' => 'Luca', 'last' => 'Ferrari', 'profession' => 'doctor', 'display' => 'Dr. med. dent. Luca Ferrari'],
            ['key' => 'reception', 'role' => 'reception', 'first' => 'Nadia', 'last' => 'Brun', 'profession' => 'reception'],
            ['key' => 'billing', 'role' => 'billing', 'first' => 'Thomas', 'last' => 'Graf', 'profession' => 'billing'],
        ];

        foreach ($people as $person) {
            $user = User::factory()
                ->forTenant($this->tenant)
                ->twoFactorEnabled()
                ->create([
                    'name' => $person['display'] ?? $person['first'].' '.$person['last'],
                    'email' => Str::slug($person['first'].' '.$person['last'], '.').'@zahnarztpraxis-morgenstern.test',
                    'password' => bcrypt(self::STAFF_PASSWORD),
                ]);

            RoleAssignment::query()->create([
                'user_id' => $user->id,
                'role_id' => Role::query()->where('key', $person['role'])->firstOrFail()->id,
                'branch_id' => null,
            ]);

            $this->users[$person['key']] = $user;

            StaffProfileFactory::new()
                ->forUser($user)
                ->atBranch($this->branch)
                ->named($person['first'], $person['last'], $person['display'] ?? null)
                ->profession($person['profession'])
                ->create();
        }
    }

    // -----------------------------------------------------------------
    // Dental fee schedule (tenant-authored — no CDT bundled)
    // -----------------------------------------------------------------

    private function seedCatalog(): void
    {
        $catalogs = app(DentalCatalogService::class);

        // The real starter template: the generic D-… procedures, all VAT 0. A dentist edits
        // these to match their practice; nothing licensed is bundled.
        $catalogs->seedStarter($this->users['owner']);

        // The dental catalog does not set a currency or validation rules on create; set both
        // in the seeder (display-only currency + an empty rule set so ChargeValidator has a
        // well-formed structure to read). Amounts stay integer minor units — reconciliation
        // is currency-agnostic, so delta stays 0.
        $catalogs->catalog()->forceFill([
            'currency' => self::CURRENCY,
            'rules' => ['market_pack' => 'dental_generic', 'validation_rules' => []],
        ])->save();

        foreach ($catalogs->list() as $procedure) {
            $this->procedures[$procedure->tariffItem->code] = $procedure;
        }
    }

    // -----------------------------------------------------------------
    // Patients
    // -----------------------------------------------------------------

    private function seedPatients(): void
    {
        $this->seedConsentTemplates();

        $curated = [
            'anna' => ['Anna', 'Vogel', '1979-05-22', 'female', 'de'],
            'beat' => ['Beat', 'Suter', '1966-02-14', 'male', 'de'],
            'carla' => ['Carla', 'Moser', '1990-11-30', 'female', 'de'],
            'david' => ['David', 'Roth', '2007-08-03', 'male', 'de'],
        ];

        $patientService = app(PatientService::class);
        $index = 0;

        foreach ($curated as $key => [$firstName, $lastName, $dob, $sex, $language]) {
            $this->patients[$key] = $patientService->create(
                [
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'date_of_birth' => $dob,
                    'sex' => $sex,
                    'preferred_language' => $language,
                    'status' => Patient::STATUS_ACTIVE,
                ],
                [
                    [
                        'type' => PatientContact::TYPE_EMAIL,
                        'value' => Str::slug($firstName.' '.$lastName, '.').'@example.test',
                        'is_primary' => true,
                    ],
                    [
                        'type' => PatientContact::TYPE_PHONE,
                        'value' => sprintf('+41 44 5%02d %02d %02d', 10 + $index, 20 + $index, 30 + $index),
                        'is_primary' => true,
                    ],
                ],
            );
            $index++;
        }

        $this->seedPortal();
    }

    private function seedConsentTemplates(): void
    {
        ConsentTemplate::query()->create([
            'key' => 'portal',
            'title' => 'Patientenportal — Zugang',
            'body' => 'I consent to accessing my dental record, documents, treatment plan and invoices through the Zahnarztpraxis Morgenstern patient portal.',
            'version' => 1,
            'scope_keys' => ['portal.access'],
            'is_active' => true,
        ]);

        ConsentTemplate::query()->create([
            'key' => 'comms',
            'title' => 'Kommunikation per E-Mail',
            'body' => 'I consent to Zahnarztpraxis Morgenstern contacting me by email about my appointments and care.',
            'version' => 1,
            'scope_keys' => ['comms.email'],
            'is_active' => true,
        ]);
    }

    /** One live portal account (Anna) so the portal treatment-plan link has a real plan to show. */
    private function seedPortal(): void
    {
        $consents = app(ConsentService::class);
        $reception = $this->users['reception'];

        foreach (['anna', 'beat'] as $key) {
            $patient = $this->patients[$key];
            $consents->grant($patient, 'comms', $patient->first_name.' '.$patient->last_name, $reception);
        }

        $anna = $this->patients['anna'];
        $consents->grant($anna, 'portal', $anna->first_name.' '.$anna->last_name, $reception);

        $portal = app(PortalAccessService::class);
        $invite = $portal->invite($anna, Str::slug($anna->first_name.' '.$anna->last_name, '.').'@example.test');
        $portal->acceptInvite($invite->plainToken, $invite->otp, self::PORTAL_PASSWORD);
    }

    // -----------------------------------------------------------------
    // The live dental surface (current week / parameterized dates)
    // -----------------------------------------------------------------

    private function seedLiveClinical(): void
    {
        $this->seedOdontograms();
        $this->seedPerform();
        $this->seedTreatmentPlans();
        $this->seedPerio();
        $this->seedDiagnoses();
        $this->seedImaging();
    }

    /**
     * Varied charted conditions across the mouths, and — on Anna — a real correction: a
     * caries charted on 16 occlusal, then superseded by a restoration with a reason, so the
     * per-tooth history view has something to show. Append-only: the caries is preserved.
     */
    private function seedOdontograms(): void
    {
        $charts = app(ToothChartService::class);
        $dentist = $this->users['owner'];

        // Anna — a multi-step correction history + a whole-tooth crown + a missing tooth.
        $charts->chart($dentist, $this->patients['anna'], '16', 'occlusal', 'caries', 'Kariöse Läsion approximal.');
        $charts->chart($dentist, $this->patients['anna'], '16', 'occlusal', 'restoration', 'Kompositfüllung gelegt.', 'Nachtrag nach Behandlung — die Läsion wurde versorgt.');
        $charts->chart($dentist, $this->patients['anna'], '26', null, 'crown', 'Keramikkrone vorhanden.');
        $charts->chart($dentist, $this->patients['anna'], '36', null, 'missing');

        // Beat — a couple of surface findings.
        $charts->chart($dentist, $this->patients['beat'], '24', 'occlusal', 'caries');
        $charts->chart($dentist, $this->patients['beat'], '46', 'occlusal', 'sealant');

        // Carla — a finding on 47 (38 is charted by the live perform below).
        $charts->chart($dentist, $this->patients['carla'], '47', 'mesial', 'caries');

        // David (a teenager) — a restoration + an implant.
        $charts->chart($dentist, $this->patients['david'], '14', 'mesial', 'restoration');
        $charts->chart($dentist, $this->patients['david'], '46', null, 'implant');
    }

    /**
     * One live performed procedure (Carla — an extraction on 38, resulting state "missing"):
     * the atomic clinical record + a real (DRAFT, unbilled) charge + the tooth-state change.
     */
    private function seedPerform(): void
    {
        app(PerformProcedureService::class)->perform(
            $this->users['owner'],
            $this->patients['carla'],
            $this->branch,
            $this->procedures['D-EXTRACT'],
            '38',
            null,
            'Extraktion 38 wegen tiefer Karies.',
            'missing',
        );
    }

    /**
     * Two plans: an ACCEPTED plan for Anna (so the portal treatment-plan link shows a real
     * plan, read-only) and a PROPOSED plan for Beat. Estimating is not billing — neither
     * posts a charge; a charge exists only when a procedure is PERFORMED.
     */
    private function seedTreatmentPlans(): void
    {
        $plans = app(TreatmentPlanService::class);
        $dentist = $this->users['owner'];

        // Anna — accepted.
        $annaPlan = $plans->create($dentist, $this->patients['anna'], 'Kronenversorgung 26');
        $annaPhase = $plans->addPhase($dentist, $annaPlan, 'Restaurative Phase');
        $plans->addItem($dentist, $annaPlan, $annaPhase, $this->procedures['D-CROWN'], '26', null);
        $annaPlan = $plans->propose($dentist, $annaPlan);
        $plans->accept($dentist, $annaPlan);

        // Beat — proposed, awaiting the patient's decision.
        $beatPlan = $plans->create($dentist, $this->patients['beat'], 'Sanierungsplan');
        $beatPhase = $plans->addPhase($dentist, $beatPlan, 'Erste Phase');
        $plans->addItem($dentist, $beatPlan, $beatPhase, $this->procedures['D-RESTOR'], '24', 'occlusal');
        $plans->addItem($dentist, $beatPlan, $beatPhase, $this->procedures['D-PROPHY'], null, null);
        $plans->propose($dentist, $beatPlan);
    }

    /**
     * Two perio exams on Anna, dated a month apart, so the per-site trend (siteHistory) is
     * demonstrable — RAW measurements only, no stage / grade / flag. The second exam is a
     * little deeper on 16: the dentist reads the change, the system never labels it.
     */
    private function seedPerio(): void
    {
        $perio = app(PerioChartService::class);
        $dentist = $this->users['owner'];
        $anna = $this->patients['anna'];

        $perio->recordExam($dentist, $anna, $this->day(10)->toDateString(), [
            ...$this->perioSites('16', pocket: [3, 3, 4, 3, 3, 4], bop: [false, false, true, false, false, false]),
            ...$this->perioSites('26', pocket: [2, 2, 3, 2, 2, 3], bop: [false, false, false, false, false, false]),
        ], 'Ausgangsbefund.');

        $perio->recordExam($dentist, $anna, $this->weekday(2)->toDateString(), [
            ...$this->perioSites('16', pocket: [3, 4, 6, 3, 4, 5], bop: [false, true, true, false, true, true]),
            ...$this->perioSites('26', pocket: [2, 3, 3, 2, 2, 3], bop: [false, false, true, false, false, false]),
        ], 'Kontrolle nach vier Wochen.');
    }

    /**
     * The six-site measurement rows for one tooth.
     *
     * @param  list<int>  $pocket
     * @param  list<bool>  $bop
     * @return list<array<string, mixed>>
     */
    private function perioSites(string $tooth, array $pocket, array $bop): array
    {
        $sites = ['mesio_buccal', 'buccal', 'disto_buccal', 'mesio_lingual', 'lingual', 'disto_lingual'];

        return array_map(fn (string $site, int $i): array => [
            'tooth' => $tooth,
            'site' => $site,
            'pocket_depth_mm' => $pocket[$i],
            'recession_mm' => 0,
            'bleeding_on_probing' => $bop[$i],
        ], $sites, array_keys($sites));
    }

    /** Dentist-authored diagnoses — recorded, never suggested. The dentist sets the status. */
    private function seedDiagnoses(): void
    {
        $diagnoses = app(DiagnosisService::class);
        $dentist = $this->users['owner'];

        $diagnoses->record($dentist, $this->patients['anna'], 'Reversible Pulpitis', 'provisional', '16', null, 'Kälteempfindlichkeit, klingt nach Reizentfernung ab.');
        $diagnoses->record($dentist, $this->patients['carla'], 'Chronische apikale Parodontitis', 'confirmed', '38', null, 'Röntgenologisch apikale Aufhellung; Zahn extrahiert.');
    }

    /** One image + a dentist-authored reading — a viewer, not an analyser (no AI, no findings). */
    private function seedImaging(): void
    {
        $imaging = app(DentalImagingService::class);
        $dentist = $this->users['owner'];

        $image = $imaging->upload(
            $dentist,
            $this->patients['anna'],
            UploadedFile::fake()->create('bitewing-16.jpg', 200, 'image/jpeg'),
            'bitewing',
            '16',
        );

        $imaging->recordReading($dentist, $image, 'Approximale Aufhellung distal an 16; klinische Korrelation empfohlen.');
    }

    // -----------------------------------------------------------------
    // Billing: a closed month that reconciles to the unit
    // -----------------------------------------------------------------

    private function seedBilling(): void
    {
        $validator = app(ChargeValidator::class);
        $issuer = app(IssueService::class);
        $payments = app(PaymentService::class);
        $dentist = $this->users['owner']; // holds billing.manage (captures the charge)
        $actor = $this->users['billing']; // validate / issue / pay

        // Capture dental charges dated into the closed month. capture() stamps service_date =
        // now(); the seeder sets the business date explicitly (D-066 — never rewind now()).
        // capture() (not perform()) is used here so the mutable Charge's service_date can be
        // back-dated — perform() also writes an APPEND-ONLY tooth record that cannot move.
        $charge = function (string $pKey, string $procCode, ?string $tooth, ?string $surface, int $day): Charge {
            $c = app(DentalChargeService::class)->capture(
                $this->users['owner'],
                $this->patients[$pKey],
                $this->branch,
                $this->procedures[$procCode],
                $tooth,
                $surface,
            );
            $c->forceFill(['service_date' => $this->day($day)->toDateString()])->save();

            return $c;
        };

        $annaCharges = [
            $charge('anna', 'D-EXAM', null, null, 5),
            $charge('anna', 'D-XRAY', null, null, 5),
            $charge('anna', 'D-RESTOR', '16', 'occlusal', 12),
        ];
        $beatCharges = [
            $charge('beat', 'D-EXAM', null, null, 6),
            $charge('beat', 'D-PROPHY', null, null, 6),
        ];
        $carlaCharges = [
            $charge('carla', 'D-EXAM', null, null, 8),
        ];

        // Validate cleanly before a single invoice is drafted.
        foreach (['anna', 'beat', 'carla'] as $pKey) {
            $result = $validator->validateForPatientPeriod(
                $this->patients[$pKey],
                $this->periodStart->toDateString(),
                $this->periodStart->endOfMonth()->toDateString(),
                $actor,
            );

            if ($result['violations'] !== []) {
                throw new RuntimeException('Demo dental charges must validate cleanly before invoicing.');
            }
        }

        // Three gapless invoices (numbers 1..3, no gaps).
        $invoices = [
            1 => $this->issue($issuer, 'anna', $annaCharges, $actor, 13, 28),
            2 => $this->issue($issuer, 'beat', $beatCharges, $actor, 28, 28),
            3 => $this->issue($issuer, 'carla', $carlaCharges, $actor, 28, 28),
        ];

        // A full payment on INV-1, a partial on INV-2, INV-3 left open — the outstanding
        // variety a real practice shows. All reconcile: open = total − paid.
        $full = $payments->record($invoices[1]->total_minor, Payment::METHOD_BANK_TRANSFER, $actor, $this->patients['anna'], 'Anna Vogel', null, $this->day(20)->toDateString());
        $payments->allocate($full, $invoices[1], $invoices[1]->total_minor, $actor);

        $half = intdiv($invoices[2]->total_minor, 2);
        $partial = $payments->record($half, Payment::METHOD_BANK_TRANSFER, $actor, $this->patients['beat'], 'Beat Suter', null, $this->day(25)->toDateString());
        $payments->allocate($partial, $invoices[2], $half, $actor);
    }

    /**
     * @param  list<Charge>  $charges
     */
    private function issue(
        IssueService $issuer,
        string $patientKey,
        array $charges,
        User $actor,
        int $issueDay,
        int $dueDay,
    ): Invoice {
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
}
