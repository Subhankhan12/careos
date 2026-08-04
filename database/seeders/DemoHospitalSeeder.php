<?php

namespace Database\Seeders;

use App\Services\EdDispositionService;
use Carbon\CarbonImmutable;
use Database\Factories\StaffProfileFactory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;
use Modules\Billing\Services\PaymentService;
use Modules\ED\Models\EdTriage;
use Modules\ED\Models\EdVisit;
use Modules\ED\Services\EdBillingService;
use Modules\ED\Services\EdDocumentationService;
use Modules\ED\Services\EdVisitService;
use Modules\ED\Services\TriageService;
use Modules\Hospital\Models\Bed;
use Modules\Hospital\Models\Stay;
use Modules\Hospital\Services\AdmissionService;
use Modules\Hospital\Services\BedBillingService;
use Modules\Hospital\Services\BedService;
use Modules\Hospital\Services\DischargeSummaryService;
use Modules\Hospital\Services\WardService;
use Modules\Lab\Models\LabOrder;
use Modules\Lab\Models\LabTest;
use Modules\Lab\Models\Specimen;
use Modules\Lab\Services\LabBillingService;
use Modules\Lab\Services\LabCatalogService;
use Modules\Lab\Services\LabOrderService;
use Modules\Lab\Services\LabResultService;
use Modules\Lab\Services\SpecimenService;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PatientContact;
use Modules\Patients\Services\PatientService;
use Modules\People\Models\StaffProfile;
use Modules\Pharmacy\Models\FormularyItem;
use Modules\Pharmacy\Models\MedicationAdministration;
use Modules\Pharmacy\Models\MedicationOrder;
use Modules\Pharmacy\Services\DispensingService;
use Modules\Pharmacy\Services\FormularyService;
use Modules\Pharmacy\Services\MedicationAdministrationService;
use Modules\Pharmacy\Services\MedicationOrderService;
use Modules\Pharmacy\Services\PharmacyBillingService;
use Modules\Pharmacy\Services\StockService;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Plan;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\SettingsService;
use Modules\Platform\Services\TenantContext;
use Modules\Radiology\Models\RadiologyExam;
use Modules\Radiology\Models\RadiologyOrder;
use Modules\Radiology\Services\ImagingStudyService;
use Modules\Radiology\Services\RadiologyBillingService;
use Modules\Radiology\Services\RadiologyCatalogService;
use Modules\Radiology\Services\RadiologyOrderService;
use Modules\Radiology\Services\RadiologyReportService;
use Modules\Surgery\Models\SurgicalCase;
use Modules\Surgery\Models\SurgicalCaseEncounter;
use Modules\Surgery\Models\SurgicalCaseTeamMember;
use Modules\Surgery\Models\SurgicalChecklistTemplateItem;
use Modules\Surgery\Services\SurgicalBillingService;
use Modules\Surgery\Services\SurgicalCaseService;
use Modules\Surgery\Services\SurgicalChecklistService;
use Modules\Surgery\Services\SurgicalStockService;
use Modules\Surgery\Services\SurgicalUsageService;
use Modules\Surgery\Services\TheatreSchedulingService;

/**
 * Klinik Bergblick — a general-hospital demo tenant that makes the SIX hospital verticals
 * (inpatient/ADT · pharmacy · surgery/OR · ED · lab · radiology) runtime-demonstrable, the way
 * {@see DemoClinicSeeder}/{@see DemoDentalSeeder}/{@see DemoSpitexSeeder} do for the outpatient
 * side. Everything is built through the REAL services — no raw-row inserts that bypass an
 * invariant — so the tenant reconciles-to-the-unit and its audit chain verifies (the paired
 * test proves both). All names/data are invented; no real PHI. German locale, CHF.
 *
 * Shape of the demo (all in the CURRENT calendar month, so `billing:reconcile` — which reconciles
 * the current period — passes for this tenant):
 *   - THE COMPOSITE EPISODE (the showpiece): one patient who arrives in the ED → is admitted
 *     (ED→ADT emergency Stay) → accrues bed-days → gets meds (order + eMAR + dispense) → a surgery
 *     (theatre + lifecycle + WHO checklist + ASA + a consumable) → labs → imaging → is discharged;
 *     the WHOLE episode bills onto ONE invoice via `invoiceStay` and reconciles δ=0.
 *   - A second, simpler elective inpatient (bed-days + meds → its own stay invoice, partly paid).
 *   - A still-admitted patient (transfer + live occupancy; bed-days accrued but NOT invoiced —
 *     draft charges, invisible to reconciliation, so the ward board shows a live patient).
 *   - An ED patient discharged home (own ED invoice, paid).
 *   - Standalone outpatient lab + radiology episodes (own invoices, left open) plus live pending
 *     states (a specimen awaiting result, a study awaiting report, ED visits mid-flow).
 *
 * FENCE: nothing here is graded, scored, staged, flagged, computed, or AI-derived. Triage acuity
 * is the nurse's ASSIGNED value; ASA is the anaesthetist's ASSIGNED class; the radiology report is
 * AUTHORED prose; lab results are raw values shown beside a displayed reference range (no flag);
 * the WHO checklist is RECORDED (partial + full), never gating a case. The certified-partner seams
 * (drug-safety, triage-acuity, PACS/DICOM) stay null-objects — no alerts, no image, no finding.
 *
 * Actor model: to guarantee no permission-gate failure while seeding, the org_admin performs the
 * service calls (it holds every hospital gate — the dental "owner does everything" precedent),
 * while role-specific StaffProfiles carry the clinical provenance (the triaging nurse, the surgeon,
 * the anaesthetist, the radiologist). A user per hospital role still exists for Playwright login.
 *
 * Idempotent: keyed on the tenant slug — a second run does nothing at all.
 *
 * Run it with:
 *   php artisan db:seed --class=DemoHospitalSeeder
 */
class DemoHospitalSeeder extends Seeder
{
    public const TENANT_SLUG = 'klinik-bergblick';

    public const TENANT_NAME = 'Klinik Bergblick';

    public const BRANCH_NAME = 'Bern Haupthaus';

    public const TIMEZONE = 'Europe/Zurich';

    public const CURRENCY = 'CHF';

    public const STAFF_PASSWORD = 'demo-password';

    /** The billing user's email — the reconciliation actor (see the paired test). */
    public const BILLING_EMAIL = 'anke.berg@klinik-bergblick.test';

    private Tenant $tenant;

    private Branch $branch;

    private CarbonImmutable $periodStart;

    /** @var array<string, User> */
    private array $users = [];

    /** @var array<string, StaffProfile> */
    private array $staff = [];

    /** @var array<string, Patient> */
    private array $patients = [];

    /** @var array<string, mixed> */
    private array $refs = [];

    /** The billing period the demo reconciles: the CURRENT calendar month. */
    public static function periodStart(): CarbonImmutable
    {
        return CarbonImmutable::today()->startOfMonth();
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

        $this->provisionTenant();
        $this->seedStaff();
        $this->seedInfrastructure();
        $this->seedCatalogs();
        $this->seedPatients();

        $this->seedCompositeEpisode();
        $this->seedElectiveInpatient();
        $this->seedStillAdmitted();
        $this->seedEdDischarged();
        $this->seedOutpatientLab();
        $this->seedOutpatientRadiology();
        $this->seedLiveEdStates();
    }

    /** The org_admin — actor for every seeding service call (holds every hospital gate). */
    private function actor(): User
    {
        return $this->users['admin'];
    }

    /** A moment early in the current month, so bed-days accrue across a plausible stay. */
    private function dayThisMonth(int $n): Carbon
    {
        return Carbon::parse($this->periodStart->addDays($n - 1)->setTime(8, 0)->toDateTimeString());
    }

    // -----------------------------------------------------------------
    // Platform
    // -----------------------------------------------------------------

    private function provisionTenant(): void
    {
        $this->call(PlanCatalogSeeder::class);

        // Tenant::created fires RbacProvisioner::provisionTenant() (system mode), seeding the
        // full role catalogue (incl. every hospital role) without polluting the audit chain.
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
            'code' => 'BE-HH',
            'timezone' => self::TIMEZONE,
        ]);
    }

    /**
     * A user per hospital role (the fixed factory 2FA secret, so each can log in via Playwright),
     * each with a StaffProfile. The org_admin is the seeding actor; the rest carry provenance.
     */
    private function seedStaff(): void
    {
        $people = [
            ['key' => 'admin', 'role' => 'org_admin', 'first' => 'Anke', 'last' => 'Berg', 'profession' => 'administrator', 'display' => 'Dr. Anke Berg'],
            ['key' => 'ward_nurse', 'role' => 'ward_nurse', 'first' => 'Lena', 'last' => 'Studer', 'profession' => 'nurse'],
            ['key' => 'charge_nurse', 'role' => 'charge_nurse', 'first' => 'Petra', 'last' => 'Frei', 'profession' => 'nurse'],
            ['key' => 'hospitalist', 'role' => 'hospitalist', 'first' => 'Martin', 'last' => 'Keller', 'profession' => 'doctor', 'display' => 'Dr. med. Martin Keller'],
            ['key' => 'bed_manager', 'role' => 'bed_manager', 'first' => 'Urs', 'last' => 'Baumann', 'profession' => 'coordinator'],
            ['key' => 'admissions_clerk', 'role' => 'admissions_clerk', 'first' => 'Rita', 'last' => 'Moser', 'profession' => 'reception'],
            ['key' => 'pharmacist', 'role' => 'pharmacist', 'first' => 'Sofia', 'last' => 'Rieder', 'profession' => 'pharmacist'],
            ['key' => 'pharmacy_technician', 'role' => 'pharmacy_technician', 'first' => 'Tim', 'last' => 'Graf', 'profession' => 'pharmacy_technician'],
            ['key' => 'surgeon', 'role' => 'surgeon', 'first' => 'Isabelle', 'last' => 'Vogt', 'profession' => 'doctor', 'display' => 'Dr. med. Isabelle Vogt'],
            ['key' => 'anesthetist', 'role' => 'anesthetist', 'first' => 'Johann', 'last' => 'Wyss', 'profession' => 'doctor', 'display' => 'Dr. med. Johann Wyss'],
            ['key' => 'scrub_nurse', 'role' => 'scrub_nurse', 'first' => 'Nadia', 'last' => 'Brun', 'profession' => 'nurse'],
            ['key' => 'surgical_scheduler', 'role' => 'surgical_scheduler', 'first' => 'Beat', 'last' => 'Suter', 'profession' => 'coordinator'],
            ['key' => 'ed_physician', 'role' => 'ed_physician', 'first' => 'Clara', 'last' => 'Meier', 'profession' => 'doctor', 'display' => 'Dr. med. Clara Meier'],
            ['key' => 'triage_nurse', 'role' => 'triage_nurse', 'first' => 'Yusuf', 'last' => 'Demir', 'profession' => 'nurse'],
            ['key' => 'ed_charge_nurse', 'role' => 'ed_charge_nurse', 'first' => 'Marco', 'last' => 'Bianchi', 'profession' => 'nurse'],
            ['key' => 'lab_tech', 'role' => 'lab_tech', 'first' => 'Elena', 'last' => 'Costa', 'profession' => 'lab_technician'],
            ['key' => 'pathologist', 'role' => 'pathologist', 'first' => 'Georg', 'last' => 'Huber', 'profession' => 'doctor', 'display' => 'Dr. med. Georg Huber'],
            ['key' => 'phlebotomist', 'role' => 'phlebotomist', 'first' => 'Sara', 'last' => 'Roth', 'profession' => 'phlebotomist'],
            ['key' => 'radiographer', 'role' => 'radiographer', 'first' => 'Fabio', 'last' => 'Ricci', 'profession' => 'radiographer'],
            ['key' => 'radiologist', 'role' => 'radiologist', 'first' => 'Miriam', 'last' => 'Lang', 'profession' => 'doctor', 'display' => 'Dr. med. Miriam Lang'],
        ];

        foreach ($people as $person) {
            $user = User::factory()
                ->forTenant($this->tenant)
                ->twoFactorEnabled()
                ->create([
                    'name' => $person['display'] ?? $person['first'].' '.$person['last'],
                    'email' => Str::slug($person['first'].' '.$person['last'], '.').'@klinik-bergblick.test',
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
                ->named($person['first'], $person['last'], $person['display'] ?? null)
                ->profession($person['profession'])
                ->create();
        }
    }

    // -----------------------------------------------------------------
    // Wards / beds / theatre (with honest, varied states)
    // -----------------------------------------------------------------

    private function seedInfrastructure(): void
    {
        $actor = $this->actor();
        $wards = app(WardService::class);
        $beds = app(BedService::class);

        $internal = $wards->create($actor, $this->branch->id, 'Innere Medizin', 'IM');
        $surgical = $wards->create($actor, $this->branch->id, 'Chirurgie', 'CH');

        // A pool of beds. Most stay free; the admissions below claim some; one is put to cleaning
        // and one blocked so the ward board shows a realistic mix of housekeeping states.
        $this->refs['beds'] = [
            'im1' => $beds->create($actor, $internal, 'IM-01', Bed::TYPE_GENERAL),
            'im2' => $beds->create($actor, $internal, 'IM-02', Bed::TYPE_GENERAL),
            'im3' => $beds->create($actor, $internal, 'IM-03', Bed::TYPE_GENERAL),
            'imIcu' => $beds->create($actor, $internal, 'IM-ICU-1', Bed::TYPE_ICU),
            'ch1' => $beds->create($actor, $surgical, 'CH-01', Bed::TYPE_GENERAL),
            'ch2' => $beds->create($actor, $surgical, 'CH-02', Bed::TYPE_GENERAL),
            'iso' => $beds->create($actor, $internal, 'IM-ISO-1', Bed::TYPE_ISOLATION),
        ];

        // IM-03: a bed mid-turnover (free → claim → release leaves it cleaning).
        $cleaning = $beds->claim($actor, $this->refs['beds']['im3']);
        $beds->release($actor, $cleaning);
        // IM-ISO-1: blocked for maintenance.
        $beds->setStatus($actor, $this->refs['beds']['iso'], Bed::STATUS_BLOCKED, 'Wartung Lüftung');

        $this->refs['theatre'] = app(TheatreSchedulingService::class)
            ->createTheatre($actor, $this->branch->id, 'OP-Saal 1', 'general');
    }

    // -----------------------------------------------------------------
    // Tenant-authored catalogs + tariffs (generic starters, no licensed data)
    // -----------------------------------------------------------------

    private function seedCatalogs(): void
    {
        $actor = $this->actor();

        // Per-diem bed-day tariffs (BED-DAY-GENERAL / -ICU / -ISOLATION).
        app(BedBillingService::class)->seedStarter($actor);

        // Formulary + prices.
        $formulary = app(FormularyService::class);
        $pharmacyBilling = app(PharmacyBillingService::class);
        $this->refs['med'] = [
            'para' => $formulary->create($actor, ['code' => 'MED-PARA-500', 'name' => 'Paracetamol', 'form' => FormularyItem::FORM_TABLET, 'strength' => '500 mg']),
            'amox' => $formulary->create($actor, ['code' => 'MED-AMOX-500', 'name' => 'Amoxicillin', 'form' => FormularyItem::FORM_CAPSULE, 'strength' => '500 mg']),
            'enox' => $formulary->create($actor, ['code' => 'MED-ENOX-40', 'name' => 'Enoxaparin', 'form' => FormularyItem::FORM_INJECTION, 'strength' => '40 mg']),
        ];
        $pharmacyBilling->priceItem($actor, $this->refs['med']['para'], 80, 'tablet');
        $pharmacyBilling->priceItem($actor, $this->refs['med']['amox'], 120, 'capsule');
        $pharmacyBilling->priceItem($actor, $this->refs['med']['enox'], 2500, 'syringe');

        // Surgical procedures / theatre-time / consumables.
        $surgeryBilling = app(SurgicalBillingService::class);
        $stock = app(SurgicalStockService::class);
        $surgeryBilling->priceProcedure($actor, 'SURG-APPEND', 'Laparoskopische Appendektomie', 250000);
        $surgeryBilling->priceTheatreTime($actor, 500, 'minute');
        $this->refs['surgItem'] = [
            'gauze' => $stock->createItem($actor, 'SURG-GAUZE', 'Steriler Tupfer', false),
            'screw' => $stock->createItem($actor, 'SURG-SCREW', 'Titanschraube', true),
        ];
        $stock->receive($actor, $this->refs['surgItem']['gauze'], 500);
        $stock->receive($actor, $this->refs['surgItem']['screw'], 20);
        $surgeryBilling->priceItem($actor, $this->refs['surgItem']['gauze'], 300, 'unit');
        $surgeryBilling->priceItem($actor, $this->refs['surgItem']['screw'], 45000, 'unit');

        // Lab tests (reference ranges are DISPLAYED data — never a computed threshold).
        $labCatalog = app(LabCatalogService::class);
        $labBilling = app(LabBillingService::class);
        $this->refs['lab'] = [
            'cbc' => $labCatalog->authorTest($actor, 'LAB-CBC', 'Blutbild', 'Blut'),
            'k' => $labCatalog->authorTest($actor, 'LAB-K', 'Kalium', 'Blut', 'mmol/L', '3.5–5.1'),
            'crp' => $labCatalog->authorTest($actor, 'LAB-CRP', 'CRP', 'Blut', 'mg/L', '< 5'),
        ];
        $labBilling->priceTest($actor, $this->refs['lab']['cbc'], 2500);
        $labBilling->priceTest($actor, $this->refs['lab']['k'], 1500);
        $labBilling->priceTest($actor, $this->refs['lab']['crp'], 1800);

        // Imaging exams.
        $radCatalog = app(RadiologyCatalogService::class);
        $radBilling = app(RadiologyBillingService::class);
        $this->refs['exam'] = [
            'cxr' => $radCatalog->authorExam($actor, 'RAD-CXR', 'Thorax-Röntgen', 'Röntgen', 'Thorax', false),
            'ct' => $radCatalog->authorExam($actor, 'RAD-CT-ABD', 'CT Abdomen', 'CT', 'Abdomen', true),
        ];
        $radBilling->priceExam($actor, $this->refs['exam']['cxr'], 4500);
        $radBilling->priceExam($actor, $this->refs['exam']['ct'], 22000);

        // ED attendance + a service line.
        $edBilling = app(EdBillingService::class);
        $edBilling->priceAttendance($actor, 9000);
        $edBilling->priceService($actor, 'ED-XRAY', 'Röntgen im Notfall', 3500);
    }

    // -----------------------------------------------------------------
    // Patients
    // -----------------------------------------------------------------

    private function seedPatients(): void
    {
        $curated = [
            'composite' => ['Karin', 'Weber', '1971-04-18', 'female'],
            'inpatient' => ['Greta', 'Zimmermann', '1958-09-02', 'female'],
            'admitted' => ['Rolf', 'Schmid', '1949-12-11', 'male'],
            'ed_home' => ['Elias', 'Kern', '1994-06-27', 'male'],
            'lab_out' => ['Marco', 'Fischer', '1983-01-05', 'male'],
            'rad_out' => ['Simone', 'Arnold', '1976-10-19', 'female'],
            'ed_arrived' => ['Nora', 'Bianchi', '2001-03-14', 'female'],
            'ed_triaged' => ['Paul', 'Widmer', '1965-07-08', 'male'],
        ];

        $patientService = app(PatientService::class);
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
                    [
                        'type' => PatientContact::TYPE_PHONE,
                        'value' => sprintf('+41 31 5%02d %02d %02d', 10 + $index, 20 + $index, 30 + $index),
                        'is_primary' => true,
                    ],
                ],
            );
            $index++;
        }
    }

    // -----------------------------------------------------------------
    // THE COMPOSITE EPISODE — every vertical on ONE reconciling invoice
    // -----------------------------------------------------------------

    private function seedCompositeEpisode(): void
    {
        $actor = $this->actor();
        $patient = $this->patients['composite'];

        // ED arrival → triage (nurse-ASSIGNED acuity) → documentation → awaiting disposition.
        $visits = app(EdVisitService::class);
        $visit = $visits->register($actor, $patient, $this->branch, EdVisit::ARRIVAL_AMBULANCE, 'Akute Bauchschmerzen rechts');
        app(TriageService::class)->record(
            $actor,
            $visit,
            $this->staff['triage_nurse'],
            'Starke Schmerzen im rechten Unterbauch, Fieber',
            EdTriage::SCALE_ESI,
            '2',
            ['systolic' => 138, 'diastolic' => 84, 'heart_rate' => 104, 'temperature_c' => 38.4, 'spo2' => 97],
        );
        $visit = $visits->transition($actor, $visit->fresh(), EdVisit::STATUS_IN_TREATMENT);
        app(EdDocumentationService::class)->startEncounter($actor, $visit, $this->staff['ed_physician'], 'Akutbeurteilung');
        $visit = $visits->transition($actor, $visit->fresh(), EdVisit::STATUS_AWAITING_DISPOSITION);

        // ED → ADT: admit to an emergency Stay (atomic; claims the bed).
        $admit = app(EdDispositionService::class)
            ->admit($actor, $visit->fresh(), $this->refs['beds']['im1'], $this->staff['hospitalist'], 'Aufnahme zur Appendektomie');
        /** @var Stay $stay */
        $stay = $admit['stay'];
        // Back-date admission (data, not the clock) so bed-days accrue across the stay — all inside
        // the current reconciliation month.
        $stay->forceFill(['admitted_at' => $this->dayThisMonth(1)->toDateTimeString()])->save();

        // ED charges (attendance + a service) — captured now, swept onto the stay invoice later.
        app(EdBillingService::class)->chargeVisit($actor, $visit->fresh(), true, ['ED-XRAY']);

        // Pharmacy: an order + eMAR (given) + a held dose + a dispense (charged, rides the stay).
        $orders = app(MedicationOrderService::class);
        $emar = app(MedicationAdministrationService::class);
        $para = $orders->prescribe($actor, $patient, $this->refs['med']['para'], [
            'dose_amount' => '1', 'dose_unit' => 'Tablette', 'route' => MedicationOrder::ROUTE_PO, 'frequency' => 'QID', 'prn' => false,
        ], $stay->id);
        $emar->record($actor, $para, ['outcome' => MedicationAdministration::OUTCOME_GIVEN]);
        $enox = $orders->prescribe($actor, $patient, $this->refs['med']['enox'], [
            'dose_amount' => '40', 'dose_unit' => 'mg', 'route' => MedicationOrder::ROUTE_SC, 'frequency' => 'OD', 'prn' => false,
        ], $stay->id);
        $emar->record($actor, $enox, ['outcome' => MedicationAdministration::OUTCOME_HELD, 'reason' => 'Vor OP pausiert']);
        app(StockService::class)->receive($actor, $this->refs['med']['para'], 200);
        $dispense = app(DispensingService::class)->dispense($actor, $para, 4);
        app(PharmacyBillingService::class)->chargeForDispense($actor, $dispense);

        // Surgery: theatre + full lifecycle + WHO checklist + ASA + a consumable. Charges ride the stay.
        $this->seedSurgeryForCase($patient, $stay->id, 'SURG-APPEND', 'Laparoskopische Appendektomie', 90, withImplant: false);

        // Lab: two ordered tests, resulted (raw values beside displayed ranges). Charges ride the stay.
        $this->seedLabResult($patient, $this->refs['lab']['cbc'], '4.9 / 11.2 / 320', null);
        $this->seedLabResult($patient, $this->refs['lab']['k'], '4.2', null);

        // Radiology: an imaging order → study → AUTHORED report. Charge rides the stay.
        $this->seedRadiologyReport(
            $patient,
            $this->refs['exam']['cxr'],
            'Lungenfelder frei, kein Erguss.',
            'Kein Hinweis auf akute kardiopulmonale Pathologie.',
        );

        // Discharge + a signed discharge summary, then ONE invoice for the whole episode.
        app(AdmissionService::class)->discharge($actor, $stay->fresh(), Stay::DISPOSITION_HOME, 'Nach Appendektomie stabil, mobilisiert');
        $summaries = app(DischargeSummaryService::class);
        $summaries->saveDraft($actor, $stay->fresh(), 'Notfallmässige Appendektomie, komplikationsloser Verlauf.', 'Wundkontrolle in 7 Tagen beim Hausarzt.');
        $summaries->finalize($actor, $stay->fresh());

        $invoice = app(BedBillingService::class)->invoiceStay($actor, $stay->fresh());
        $this->payInFull($invoice, $patient, 'Karin Weber', 5);
    }

    // -----------------------------------------------------------------
    // A second, simpler elective inpatient (bed-days + meds → own invoice, partly paid)
    // -----------------------------------------------------------------

    private function seedElectiveInpatient(): void
    {
        $actor = $this->actor();
        $patient = $this->patients['inpatient'];

        $stay = app(AdmissionService::class)->admit(
            $actor,
            $patient,
            $this->refs['beds']['ch1'],
            $this->staff['hospitalist'],
            Stay::TYPE_ELECTIVE,
            'Elektive Beobachtung',
        );
        $stay->forceFill(['admitted_at' => $this->dayThisMonth(2)->toDateTimeString()])->save();

        $order = app(MedicationOrderService::class)->prescribe($actor, $patient, $this->refs['med']['amox'], [
            'dose_amount' => '1', 'dose_unit' => 'Kapsel', 'route' => MedicationOrder::ROUTE_PO, 'frequency' => 'TID', 'prn' => false,
        ], $stay->id);
        app(MedicationAdministrationService::class)->record($actor, $order, ['outcome' => MedicationAdministration::OUTCOME_REFUSED, 'reason' => 'Patientin verweigert']);
        app(StockService::class)->receive($actor, $this->refs['med']['amox'], 100);
        $dispense = app(DispensingService::class)->dispense($actor, $order, 3);
        app(PharmacyBillingService::class)->chargeForDispense($actor, $dispense);

        app(AdmissionService::class)->discharge($actor, $stay->fresh(), Stay::DISPOSITION_HOME, 'Stabil');
        $invoice = app(BedBillingService::class)->invoiceStay($actor, $stay->fresh());

        // A partial payment — the outstanding variety a real ward shows.
        $half = intdiv($invoice->total_minor, 2);
        $payment = app(PaymentService::class)->record($half, Payment::METHOD_BANK_TRANSFER, $actor, $patient, 'Greta Zimmermann', null, $this->dayThisMonth(6)->toDateString());
        app(PaymentService::class)->allocate($payment, $invoice, $half, $actor);
    }

    // -----------------------------------------------------------------
    // A still-admitted patient (transfer + live occupancy; draft bed-days, no invoice)
    // -----------------------------------------------------------------

    private function seedStillAdmitted(): void
    {
        $actor = $this->actor();
        $patient = $this->patients['admitted'];

        $stay = app(AdmissionService::class)->admit(
            $actor,
            $patient,
            $this->refs['beds']['im2'],
            $this->staff['hospitalist'],
            Stay::TYPE_ELECTIVE,
            'Kardiale Abklärung',
        );
        $stay->forceFill(['admitted_at' => $this->dayThisMonth(1)->toDateTimeString()])->save();

        // A transfer to the ICU — shows the live transfer path + occupancy on two beds' history.
        app(AdmissionService::class)->transfer($actor, $stay->fresh(), $this->refs['beds']['imIcu'], 'Überwachung erforderlich');

        // Accrue bed-days but leave them UNBILLED (draft) — the patient is still in the ward, and
        // draft charges are invisible to reconciliation.
        app(BedBillingService::class)->accrueBedDays($actor, $stay->fresh());
    }

    // -----------------------------------------------------------------
    // ED discharged home — its own reconciling ED invoice
    // -----------------------------------------------------------------

    private function seedEdDischarged(): void
    {
        $actor = $this->actor();
        $patient = $this->patients['ed_home'];
        $visits = app(EdVisitService::class);

        $visit = $visits->register($actor, $patient, $this->branch, EdVisit::ARRIVAL_WALK_IN, 'Knöchelverletzung nach Sturz');
        app(TriageService::class)->record($actor, $visit, $this->staff['triage_nurse'], 'Schmerzen und Schwellung linker Knöchel', EdTriage::SCALE_ESI, '4', ['heart_rate' => 78, 'spo2' => 99]);
        $visit = $visits->transition($actor, $visit->fresh(), EdVisit::STATUS_IN_TREATMENT);
        app(EdDocumentationService::class)->startEncounter($actor, $visit, $this->staff['ed_physician'], 'Beurteilung Knöchel');
        $visit = $visits->transition($actor, $visit->fresh(), EdVisit::STATUS_AWAITING_DISPOSITION);

        app(EdDispositionService::class)->discharge($actor, $visit->fresh(), 'Keine Fraktur, häusliche Behandlung, Hausarzt-Kontrolle');

        app(EdBillingService::class)->chargeVisit($actor, $visit->fresh(), true, ['ED-XRAY']);
        $invoice = app(EdBillingService::class)->invoiceVisit($actor, $visit->fresh());
        $this->payInFull($invoice, $patient, 'Elias Kern', 3);
    }

    // -----------------------------------------------------------------
    // Standalone outpatient lab (own invoice, open) + a live pending specimen
    // -----------------------------------------------------------------

    private function seedOutpatientLab(): void
    {
        $actor = $this->actor();
        $patient = $this->patients['lab_out'];

        // A completed outpatient CRP → resulted → charged → invoiced (left open).
        $labOrder = app(LabOrderService::class)->place($actor, $patient, $this->refs['lab']['crp'], LabOrder::PRIORITY_ROUTINE)['labOrder'];
        $specimen = app(SpecimenService::class)->collect($actor, $labOrder);
        app(SpecimenService::class)->transition($actor, $specimen, Specimen::STATUS_IN_LAB);
        app(LabResultService::class)->record($actor, $specimen->fresh(), ['value' => '3.1']);
        app(LabBillingService::class)->chargeOrder($actor, $labOrder->fresh());
        app(LabBillingService::class)->invoiceOrder($actor, $labOrder->fresh());

        // A live pending specimen — collected, awaiting result (the lab worklist shows work to do).
        $pending = app(LabOrderService::class)->place($actor, $patient, $this->refs['lab']['k'], LabOrder::PRIORITY_STAT)['labOrder'];
        app(SpecimenService::class)->collect($actor, $pending);
    }

    // -----------------------------------------------------------------
    // Standalone outpatient radiology (own invoice, open) + a live pending study
    // -----------------------------------------------------------------

    private function seedOutpatientRadiology(): void
    {
        $actor = $this->actor();
        $patient = $this->patients['rad_out'];

        // A completed outpatient chest X-ray → study → authored report → charged → invoiced (open).
        $order = app(RadiologyOrderService::class)->place($actor, $patient, $this->refs['exam']['cxr'], RadiologyOrder::PRIORITY_ROUTINE)['radiologyOrder'];
        $study = app(ImagingStudyService::class)->acquire($actor, $order);
        $report = app(RadiologyReportService::class);
        $report->saveDraft($actor, $study, $this->staff['radiologist'], 'Herzgrösse normal, Lungenfelder frei.', 'Unauffälliger Thorax.');
        $report->sign($actor, $study->fresh());
        app(RadiologyBillingService::class)->chargeOrder($actor, $order->fresh());
        app(RadiologyBillingService::class)->invoiceOrder($actor, $order->fresh());

        // A live pending study — acquired, awaiting the radiologist's report (the worklist).
        $pending = app(RadiologyOrderService::class)->place($actor, $patient, $this->refs['exam']['ct'], RadiologyOrder::PRIORITY_URGENT)['radiologyOrder'];
        app(ImagingStudyService::class)->acquire($actor, $pending);
    }

    // -----------------------------------------------------------------
    // Live ED board states — patients mid-flow (no billing)
    // -----------------------------------------------------------------

    private function seedLiveEdStates(): void
    {
        $actor = $this->actor();
        $visits = app(EdVisitService::class);

        // Just arrived, not yet triaged.
        $visits->register($actor, $this->patients['ed_arrived'], $this->branch, EdVisit::ARRIVAL_WALK_IN, 'Kopfschmerzen seit heute Morgen');

        // Triaged, waiting for a treatment space.
        $triaged = $visits->register($actor, $this->patients['ed_triaged'], $this->branch, EdVisit::ARRIVAL_WALK_IN, 'Husten und Fieber');
        app(TriageService::class)->record($actor, $triaged, $this->staff['triage_nurse'], 'Produktiver Husten, Fieber 38.1°C', EdTriage::SCALE_ESI, '3', ['heart_rate' => 92, 'temperature_c' => 38.1, 'spo2' => 96]);
    }

    // -----------------------------------------------------------------
    // Reusable episode helpers
    // -----------------------------------------------------------------

    /** A full surgical case: theatre slot, lifecycle, team, ASA, WHO checklist, a consumable, charges. */
    private function seedSurgeryForCase(Patient $patient, string $stayId, string $procedureCode, string $description, int $theatreMinutes, bool $withImplant): void
    {
        $actor = $this->actor();
        $cases = app(SurgicalCaseService::class);

        $case = $cases->schedule($actor, $patient, $this->staff['surgeon'], $description, $this->dayThisMonth(2), $stayId);
        app(TheatreSchedulingService::class)->bookSlot($actor, $this->refs['theatre'], $this->dayThisMonth(2), $theatreMinutes, $case);
        $cases->addTeamMember($actor, $case, $this->staff['anesthetist'], SurgicalCaseTeamMember::ROLE_ANESTHETIST);
        $cases->addTeamMember($actor, $case, $this->staff['scrub_nurse'], SurgicalCaseTeamMember::ROLE_SCRUB_NURSE);
        $cases->recordAnesthesiaAssessment($actor, $case, 'II', 'I', $this->staff['anesthetist']);

        // WHO checklist RECORDED (a couple of items across phases) — never gates the case.
        $checklist = app(SurgicalChecklistService::class);
        $checklist->openChecklist($actor, $case);
        foreach (SurgicalChecklistTemplateItem::query()->orderBy('phase')->orderBy('display_order')->take(4)->get() as $item) {
            $checklist->confirmItem($actor, $case, $item->id, true, null);
        }

        // Lifecycle → completed → post_op, with an operative note authored along the way.
        foreach ([SurgicalCase::STATUS_PRE_OP, SurgicalCase::STATUS_IN_PROGRESS] as $to) {
            $case = $cases->transition($actor, $case->fresh(), $to);
        }
        $cases->startNote($actor, $case->fresh(), SurgicalCaseEncounter::PHASE_OPERATIVE);
        app(SurgicalUsageService::class)->recordUsage($actor, $case->fresh(), $this->refs['surgItem']['gauze'], 8);
        if ($withImplant) {
            app(SurgicalUsageService::class)->placeImplant($actor, $case->fresh(), $this->refs['surgItem']['screw'], 'LOT-BB-2211', 'SN-'.strtoupper(Str::random(6)), 'UDI-DEMO-0001', 'Implantat demonstriert');
        }
        foreach ([SurgicalCase::STATUS_COMPLETED, SurgicalCase::STATUS_POST_OP] as $to) {
            $case = $cases->transition($actor, $case->fresh(), $to);
        }

        // Charges (procedure + theatre-time + consumable) — DRAFT, swept onto the stay invoice.
        app(SurgicalBillingService::class)->chargeCase($actor, $case->fresh(), $procedureCode, $theatreMinutes);
    }

    /** A lab test resulted for a patient: order → specimen → in_lab → raw result + charge. */
    private function seedLabResult(Patient $patient, LabTest $test, ?string $value, ?string $documentId): void
    {
        $actor = $this->actor();
        $labOrder = app(LabOrderService::class)->place($actor, $patient, $test, LabOrder::PRIORITY_ROUTINE)['labOrder'];
        $specimen = app(SpecimenService::class)->collect($actor, $labOrder);
        app(SpecimenService::class)->transition($actor, $specimen, Specimen::STATUS_IN_LAB);
        app(LabResultService::class)->record($actor, $specimen->fresh(), ['value' => $value, 'document_id' => $documentId]);
        app(LabBillingService::class)->chargeOrder($actor, $labOrder->fresh());
    }

    /** An imaging exam reported for a patient: order → study acquired → authored+signed report + charge. */
    private function seedRadiologyReport(Patient $patient, RadiologyExam $exam, string $findings, string $impression): void
    {
        $actor = $this->actor();
        $order = app(RadiologyOrderService::class)->place($actor, $patient, $exam, RadiologyOrder::PRIORITY_ROUTINE)['radiologyOrder'];
        $study = app(ImagingStudyService::class)->acquire($actor, $order);
        $report = app(RadiologyReportService::class);
        $report->saveDraft($actor, $study, $this->staff['radiologist'], $findings, $impression);
        $report->sign($actor, $study->fresh());
        app(RadiologyBillingService::class)->chargeOrder($actor, $order->fresh());
    }

    private function payInFull(Invoice $invoice, Patient $patient, string $payerName, int $day): void
    {
        $actor = $this->actor();
        $payment = app(PaymentService::class)->record($invoice->total_minor, Payment::METHOD_BANK_TRANSFER, $actor, $patient, $payerName, null, $this->dayThisMonth($day)->toDateString());
        app(PaymentService::class)->allocate($payment, $invoice, $invoice->total_minor, $actor);
    }
}
