<?php

use App\Http\Controllers\AiApprovalQueueController;
use App\Http\Controllers\AppLandingController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\ClinicalSummaryDraftController;
use App\Http\Controllers\ClinicalSummaryInsertController;
use App\Http\Controllers\Comms\InboxAgentController;
use App\Http\Controllers\GovernanceDashboardController;
use App\Http\Controllers\KbArticleController;
use App\Http\Controllers\Portal\PortalHomeController;
use App\Http\Controllers\ResourceController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Modules\Billing\Http\Controllers\AgingController;
use Modules\Billing\Http\Controllers\CreditNoteController;
use Modules\Billing\Http\Controllers\DunningController;
use Modules\Billing\Http\Controllers\InvoiceController;
use Modules\Billing\Http\Controllers\InvoiceDraftController;
use Modules\Billing\Http\Controllers\PaymentController;
use Modules\Billing\Http\Controllers\PortalInvoiceController;
use Modules\Clinical\Http\Controllers\ClinicalChartController;
use Modules\Clinical\Http\Controllers\ClinicalNoteShowController;
use Modules\Clinical\Http\Controllers\DocumentDeleteController;
use Modules\Clinical\Http\Controllers\DocumentDownloadController;
use Modules\Clinical\Http\Controllers\DocumentShareController;
use Modules\Clinical\Http\Controllers\DocumentUploadController;
use Modules\Clinical\Http\Controllers\EncounterShowController;
use Modules\Clinical\Http\Controllers\NoteEditorController;
use Modules\Clinical\Http\Controllers\OpenEncounterFromAppointmentController;
use Modules\Clinical\Http\Controllers\OrderableItemController;
use Modules\Clinical\Http\Controllers\OrderController;
use Modules\Clinical\Http\Controllers\OrdersReviewController;
use Modules\Clinical\Http\Controllers\PortalDocumentController;
use Modules\Clinical\Http\Controllers\SnippetController;
use Modules\Comms\Http\Controllers\InboxActionController;
use Modules\Comms\Http\Controllers\InboxController;
use Modules\Comms\Http\Controllers\PortalMessageController;
use Modules\Comms\Http\Controllers\PortalTelehealthController;
use Modules\Comms\Http\Controllers\StaffTelehealthController;
use Modules\Dental\Http\Controllers\DentalImageController;
use Modules\Dental\Http\Controllers\DentalLandingController;
use Modules\Dental\Http\Controllers\DiagnosisController;
use Modules\Dental\Http\Controllers\FeeScheduleController;
use Modules\Dental\Http\Controllers\OdontogramController;
use Modules\Dental\Http\Controllers\PerioChartController;
use Modules\Dental\Http\Controllers\PortalTreatmentPlanController;
use Modules\Dental\Http\Controllers\TreatmentPlanController;
use Modules\ED\Http\Controllers\EdBoardController;
use Modules\ED\Http\Controllers\EdTriageController;
use Modules\FrontDesk\Http\Controllers\KioskCheckInController;
use Modules\FrontDesk\Http\Controllers\KioskDeviceController;
use Modules\FrontDesk\Http\Controllers\PortalCheckInController;
use Modules\Hospital\Http\Controllers\AdmissionController;
use Modules\Hospital\Http\Controllers\BedBillingController;
use Modules\Hospital\Http\Controllers\BedsideChartController;
use Modules\Hospital\Http\Controllers\DischargeSummaryController;
use Modules\Hospital\Http\Controllers\HandoverController;
use Modules\Hospital\Http\Controllers\WardBoardController;
use Modules\Import\Http\Controllers\ImportBatchController;
use Modules\Nursing\Http\Controllers\CompetencyController;
use Modules\Nursing\Http\Controllers\DispatchActionController;
use Modules\Nursing\Http\Controllers\DispatchBoardController;
use Modules\Patients\Http\Controllers\PatientConsentController;
use Modules\Patients\Http\Controllers\PatientIndexController;
use Modules\Patients\Http\Controllers\PatientRegistrationController;
use Modules\Patients\Http\Controllers\PatientShowController;
use Modules\Patients\Http\Controllers\PortalAuthController;
use Modules\Patients\Http\Controllers\PortalConsentController;
use Modules\Patients\Http\Controllers\PortalInvitationController;
use Modules\Pharmacy\Http\Controllers\DispensingController;
use Modules\Pharmacy\Http\Controllers\FormularyController;
use Modules\Pharmacy\Http\Controllers\InventoryController;
use Modules\Pharmacy\Http\Controllers\MedicationAdministrationController;
use Modules\Pharmacy\Http\Controllers\MedicationOrderController;
use Modules\Pharmacy\Http\Controllers\PricingController;
use Modules\Platform\Http\Controllers\SettingsController;
use Modules\Platform\Http\Controllers\UserRoleController;
use Modules\Reporting\Http\Controllers\ReportingDashboardController;
use Modules\Scheduling\Http\Controllers\AppointmentSeriesController;
use Modules\Scheduling\Http\Controllers\DayBoardActionController;
use Modules\Scheduling\Http\Controllers\DayBoardController;
use Modules\Scheduling\Http\Controllers\PortalAppointmentController;
use Modules\Scheduling\Http\Controllers\PublicBookingController;
use Modules\Scheduling\Http\Controllers\WaitlistOfferController;
use Modules\Surgery\Http\Controllers\CaseSuppliesController;
use Modules\Surgery\Http\Controllers\SurgicalBillingController;
use Modules\Surgery\Http\Controllers\SurgicalCaseController;
use Modules\Surgery\Http\Controllers\SurgicalChecklistController;
use Modules\Surgery\Http\Controllers\SurgicalInventoryController;
use Modules\Surgery\Http\Controllers\SurgicalPricingController;

Route::get('/', function () {
    if (! auth()->check()) {
        return redirect()->route('login');
    }

    return redirect(auth()->user()->isSuperAdmin() ? '/admin' : '/app');
});

Route::middleware('auth')->group(function () {
    // Tenant app shell. Tenant identification + mandatory-MFA run in the web group.
    Route::get('/app', AppLandingController::class)->name('app.landing');

    // Platform admin shell (super-admins only).
    Route::middleware('super-admin')
        ->get('/admin', fn () => Inertia::render('Admin/Landing'))
        ->name('admin.landing');

    // Mandatory MFA enrollment — the target EnsureTwoFactorEnabled routes un-enrolled
    // users to (and which it exempts so they are not locked out).
    Route::get('/two-factor/enrollment', fn () => Inertia::render('Auth/TwoFactorEnroll'))
        ->name('two-factor.enrollment');

    Route::get('/patients', PatientIndexController::class)->name('patients.index');
    Route::get('/patients/register', [PatientRegistrationController::class, 'create'])->name('patients.register');
    Route::post('/patients', [PatientRegistrationController::class, 'store'])->name('patients.store');
    Route::post('/patients/duplicates', [PatientRegistrationController::class, 'duplicates'])->name('patients.duplicates.check');
    Route::get('/patients/{patient}', PatientShowController::class)->name('patients.show');
    Route::post('/patients/{patient}/consents', [PatientConsentController::class, 'grant'])->name('patients.consents.grant');
    Route::post('/patients/{patient}/consents/{consent}/withdraw', [PatientConsentController::class, 'withdraw'])
        ->name('patients.consents.withdraw');

    Route::get('/scheduling/day-board', DayBoardController::class)->name('scheduling.day-board');
    Route::post('/scheduling/day-board/transition', [DayBoardActionController::class, 'transition'])
        ->name('scheduling.day-board.transition');
    Route::post('/scheduling/day-board/quick-book', [DayBoardActionController::class, 'quickBook'])
        ->name('scheduling.day-board.quick-book');
    Route::post('/scheduling/day-board/slots', [DayBoardActionController::class, 'slots'])
        ->name('scheduling.day-board.slots');
    Route::post('/scheduling/day-board/open-encounter', OpenEncounterFromAppointmentController::class)
        ->name('scheduling.day-board.open-encounter');

    // Waitlist auto-fill (P0P.G9): surface candidates for a freed slot, offer,
    // then accept/decline. All appointment.manage-gated on the branch.
    Route::post('/scheduling/waitlist/candidates', [WaitlistOfferController::class, 'candidates'])
        ->name('scheduling.waitlist.candidates');
    Route::post('/scheduling/waitlist/offer', [WaitlistOfferController::class, 'offer'])
        ->name('scheduling.waitlist.offer');
    Route::post('/scheduling/waitlist/offers/accept', [WaitlistOfferController::class, 'accept'])
        ->name('scheduling.waitlist.accept');
    Route::post('/scheduling/waitlist/offers/decline', [WaitlistOfferController::class, 'decline'])
        ->name('scheduling.waitlist.decline');

    // Recurring / series appointments (P0P.G8): preview occurrence dates with a
    // per-date free/conflict indicator, then book the free ones through the
    // no-double-book engine. All appointment.manage-gated on the branch.
    Route::post('/scheduling/series/preview', [AppointmentSeriesController::class, 'preview'])
        ->name('scheduling.series.preview');
    Route::post('/scheduling/series', [AppointmentSeriesController::class, 'store'])
        ->name('scheduling.series.store');
    Route::post('/scheduling/series/end', [AppointmentSeriesController::class, 'end'])
        ->name('scheduling.series.end');

    Route::get('/comms/inbox', InboxController::class)->name('comms.inbox');
    Route::post('/comms/inbox/reply', [InboxActionController::class, 'reply'])->name('comms.inbox.reply');
    Route::post('/comms/inbox/status', [InboxActionController::class, 'status'])->name('comms.inbox.status');
    Route::post('/comms/inbox/assign', [InboxActionController::class, 'assign'])->name('comms.inbox.assign');
    Route::post('/comms/inbox/ai-draft', [InboxAgentController::class, 'draft'])->name('comms.inbox.ai-draft');
    Route::post('/comms/inbox/send-draft', [InboxAgentController::class, 'sendDraft'])->name('comms.inbox.send-draft');

    Route::get('/nursing/dispatch', DispatchBoardController::class)->name('nursing.dispatch');
    Route::post('/nursing/dispatch/assign', [DispatchActionController::class, 'assign'])
        ->name('nursing.dispatch.assign');
    Route::post('/nursing/dispatch/unassign', [DispatchActionController::class, 'unassign'])
        ->name('nursing.dispatch.unassign');

    // Tenant-authored nurse competencies + per-nurse grants (competency.manage).
    Route::get('/nursing/competencies', [CompetencyController::class, 'index'])
        ->name('nursing.competencies.index');
    Route::post('/nursing/competencies', [CompetencyController::class, 'store'])
        ->name('nursing.competencies.store');
    Route::post('/nursing/competencies/update', [CompetencyController::class, 'update'])
        ->name('nursing.competencies.update');
    Route::post('/nursing/competencies/grant', [CompetencyController::class, 'grant'])
        ->name('nursing.competencies.grant');
    Route::post('/nursing/competencies/revoke', [CompetencyController::class, 'revoke'])
        ->name('nursing.competencies.revoke');
    Route::post('/nursing/competencies/seed', [CompetencyController::class, 'seed'])
        ->name('nursing.competencies.seed');

    Route::get('/clinical/encounters/{encounter}', EncounterShowController::class)
        ->name('clinical.encounters.show');
    Route::get('/clinical/notes/{note}', ClinicalNoteShowController::class)
        ->name('clinical.notes.show');
    Route::get('/clinical/chart/{patient}', ClinicalChartController::class)
        ->name('clinical.chart');
    Route::post('/clinical/chart/{patient}/summary-draft', [ClinicalSummaryDraftController::class, 'store'])
        ->name('clinical.summary.draft');
    Route::post('/clinical/chart/{patient}/summary-insert', [ClinicalSummaryInsertController::class, 'store'])
        ->name('clinical.summary.insert');
    Route::post('/clinical/encounters/{encounter}/notes', [NoteEditorController::class, 'store'])
        ->name('clinical.notes.store');
    Route::get('/clinical/notes/{note}/edit', [NoteEditorController::class, 'edit'])
        ->name('clinical.notes.edit');
    Route::patch('/clinical/notes/{note}', [NoteEditorController::class, 'update'])
        ->name('clinical.notes.update');
    Route::post('/clinical/notes/{note}/sign', [NoteEditorController::class, 'sign'])
        ->name('clinical.notes.sign');
    Route::post('/clinical/notes/{note}/amend', [NoteEditorController::class, 'amend'])
        ->name('clinical.notes.amend');
    // Structured clinical orders (P0P.G11): place/track/result/review + the
    // tenant-authored orderable catalog + the "orders to review" worklist.
    Route::post('/clinical/orders', [OrderController::class, 'place'])->name('clinical.orders.place');
    Route::post('/clinical/orders/transition', [OrderController::class, 'transition'])->name('clinical.orders.transition');
    Route::post('/clinical/orders/result', [OrderController::class, 'result'])->name('clinical.orders.result');
    Route::post('/clinical/orders/mark-reviewed', [OrderController::class, 'review'])->name('clinical.orders.review');
    Route::get('/clinical/orders/review', OrdersReviewController::class)->name('clinical.orders.worklist');
    Route::get('/clinical/orderable-items', [OrderableItemController::class, 'index'])->name('clinical.orderable-items.index');
    Route::post('/clinical/orderable-items', [OrderableItemController::class, 'store'])->name('clinical.orderable-items.store');
    Route::post('/clinical/orderable-items/deactivate', [OrderableItemController::class, 'deactivate'])->name('clinical.orderable-items.deactivate');

    // Clinical dot-phrases / quick-text macros (P0P.G10): personal + shared.
    Route::get('/clinical/snippets', [SnippetController::class, 'index'])->name('clinical.snippets.index');
    Route::post('/clinical/snippets', [SnippetController::class, 'store'])->name('clinical.snippets.store');
    Route::post('/clinical/snippets/update', [SnippetController::class, 'update'])->name('clinical.snippets.update');
    Route::post('/clinical/snippets/delete', [SnippetController::class, 'delete'])->name('clinical.snippets.delete');

    Route::post('/clinical/patients/{patient}/documents', DocumentUploadController::class)
        ->name('clinical.documents.upload');
    Route::get('/clinical/documents/{document}', DocumentDownloadController::class)
        ->name('clinical.documents.download');
    Route::post('/clinical/documents/{document}/share', [DocumentShareController::class, 'share'])
        ->name('clinical.documents.share');
    Route::post('/clinical/documents/{document}/unshare', [DocumentShareController::class, 'unshare'])
        ->name('clinical.documents.unshare');
    Route::delete('/clinical/documents/{document}', DocumentDeleteController::class)
        ->name('clinical.documents.delete');

    // Dental section landing (DENTAL.G9) — a PATIENT PICKER so the dental vertical is reachable
    // from the top nav (dental work is patient-scoped; there is no patient-independent clinical
    // dental route). dental.chart-gated, PRESENTATIONAL. GET /dental has no collision — every
    // other /dental/* route carries a static second segment.
    Route::get('/dental', DentalLandingController::class)->name('dental.index');

    // Dental odontogram chart (DENTAL.G2) — the interactive tooth chart, PRESENTATIONAL
    // over the G1 ToothChartService (append-only, audited, patient-scoped read-logged).
    // show = patient.view (read), store/charting = dental.chart. String-id {patient}
    // (FIX.1). Render-not-judge: the payload carries charted facts only.
    Route::get('/dental/chart/{patient}', [OdontogramController::class, 'show'])->name('dental.chart');
    Route::post('/dental/chart/{patient}', [OdontogramController::class, 'store'])->name('dental.chart.store');
    // Perform a procedure (DENTAL.G4) — one atomic action: clinical record + charge (through the
    // existing G3 billing path) + any tooth-state change (through G1's append-only charting).
    // dental.chart-gated (clinical); the charge enforces billing.manage inside the service.
    Route::post('/dental/chart/{patient}/perform', [OdontogramController::class, 'perform'])->name('dental.chart.perform');

    // Dental perio charting (DENTAL.G6) — per-tooth, per-site periodontal measurements as RAW
    // recorded facts (record-not-judge: NO staging/grade/severity/risk/auto-flag anywhere).
    // Append-only exams (a re-exam is a new exam). show = patient.view (read), store = dental.chart.
    // String-id {patient} (FIX.1).
    Route::get('/dental/perio/{patient}', [PerioChartController::class, 'show'])->name('dental.perio');
    Route::post('/dental/perio/{patient}', [PerioChartController::class, 'store'])->name('dental.perio.store');

    // Dental diagnosis (DENTAL.G7) — a DENTIST-AUTHORED clinical diagnosis, recorded. The sharpest
    // fence: NO AI, NO suggested/proposed diagnosis, NO auto-ranked differential — the dentist
    // enters it (from their own tenant pick-list or free text) and sets the status; the system only
    // records. show = patient.view (read), store = dental.chart. The term route adds to the tenant's
    // own pick-list (dental.chart). String-id {patient} (FIX.1).
    Route::get('/dental/diagnoses/{patient}', [DiagnosisController::class, 'show'])->name('dental.diagnoses');
    Route::post('/dental/diagnoses/{patient}', [DiagnosisController::class, 'store'])->name('dental.diagnoses.store');
    Route::post('/dental/diagnosis-terms', [DiagnosisController::class, 'storeTerm'])->name('dental.diagnosis-terms.store');

    // Dental imaging (DENTAL.G8) — upload + a basic 2D viewer + a DENTIST-authored reading, over the
    // EXISTING clinical document storage (private disk, tenant-prefixed, no public URL). NO AI/CV: the
    // system displays the image + records the dentist's reading, it never analyses the pixels. Live
    // capture / DICOM / 3D overlay / AI detection are PARTNER-GATED / NON-GOAL (see DEFERRED). show +
    // file = patient.view, upload + reading = dental.chart. String-id params (FIX.1). The static
    // image-file route uses a distinct prefix so it never collides with the {patient} gallery route.
    Route::get('/dental/images/{patient}', [DentalImageController::class, 'show'])->name('dental.imaging');
    Route::post('/dental/images/{patient}', [DentalImageController::class, 'store'])->name('dental.imaging.store');
    Route::post('/dental/images/{image}/reading', [DentalImageController::class, 'storeReading'])->name('dental.imaging.reading');
    Route::get('/dental/image-file/{image}', [DentalImageController::class, 'download'])->name('dental.imaging.file');

    // Dental treatment plans (DENTAL.G5) — a DENTIST-AUTHORED phased plan with a fee-schedule
    // ESTIMATE (snapshotted at proposal, reusing the G3 pricing). The plan ESTIMATES; performing
    // a planned item CHARGES through G4 (no double-charge). Read = patient.view, manage/perform =
    // dental.chart (+ billing.manage for the charge). String-id params (FIX.1).
    Route::get('/dental/plans/{patient}', [TreatmentPlanController::class, 'index'])->name('dental.plans');
    Route::post('/dental/plans/{patient}', [TreatmentPlanController::class, 'store'])->name('dental.plans.store');
    Route::post('/dental/plans/{plan}/phases', [TreatmentPlanController::class, 'addPhase'])->name('dental.plans.phases');
    Route::post('/dental/plans/{plan}/items', [TreatmentPlanController::class, 'addItem'])->name('dental.plans.items');
    Route::post('/dental/plans/{plan}/transition', [TreatmentPlanController::class, 'transition'])->name('dental.plans.transition');
    Route::post('/dental/plan-items/{item}/perform', [TreatmentPlanController::class, 'performItem'])->name('dental.plan-items.perform');

    // Dental fee schedule (DENTAL.G3) — the tenant's procedure catalog, authored over the
    // EXISTING billing tariff engine (a procedure IS a tariff item; charging snapshots the
    // fee through ChargeCaptureService — no new billing logic). billing.manage-gated. The
    // static /seed path is registered before the {id} sibling so it is never captured.
    Route::get('/dental/fee-schedule', [FeeScheduleController::class, 'index'])->name('dental.fee-schedule');
    Route::post('/dental/fee-schedule', [FeeScheduleController::class, 'store'])->name('dental.fee-schedule.store');
    Route::post('/dental/fee-schedule/seed', [FeeScheduleController::class, 'seed'])->name('dental.fee-schedule.seed');
    Route::post('/dental/fee-schedule/{id}', [FeeScheduleController::class, 'update'])->name('dental.fee-schedule.update');

    // Inpatient ADT (HOSPITAL.G2) — admit / transfer / discharge a Stay. The admit/transfer/
    // discharge WRITES are ATOMIC + concurrency-safe in AdmissionService (stay change + bed
    // claim/release + append-only event in one transaction); no charge is posted (billing is G6).
    // show = patient.view (a ward nurse views an admission, read-logged); writes = admission.manage.
    // String-id {stay} (FIX.1). The rich ward board is HOSPITAL.G3.
    Route::get('/hospital/admissions/{stay}', [AdmissionController::class, 'show'])->name('hospital.admissions.show');
    Route::post('/hospital/admissions', [AdmissionController::class, 'store'])->name('hospital.admissions.store');
    Route::post('/hospital/admissions/{stay}/transfer', [AdmissionController::class, 'transfer'])->name('hospital.admissions.transfer');
    Route::post('/hospital/admissions/{stay}/discharge', [AdmissionController::class, 'discharge'])->name('hospital.admissions.discharge');

    // Ward board (HOSPITAL.G3) — the live bed-occupancy cockpit: a ward's beds by housekeeping status +
    // the current patient per occupied bed + a plain occupancy count, with the ADT/bed actions surfaced
    // through the EXISTING G1/G2 services (no new ADT logic; admit-from-board uses the proven claim).
    // Read = patient.view (inpatient staff incl. ward nurses); bed-status write = bed.manage. Operational
    // only — NO acuity/severity/priority. String-id {bed} (FIX.1).
    Route::get('/hospital/wards', [WardBoardController::class, 'show'])->name('hospital.wards');
    Route::post('/hospital/beds/{bed}/status', [WardBoardController::class, 'setBedStatus'])->name('hospital.beds.status');

    // Bedside charting (HOSPITAL.G4) — a stay's chart REUSES the tested Clinical module: a ward round
    // is a reused Encounter tied to the stay (a Hospital-side WardRound link; Encounter UNMODIFIED),
    // its note is the existing sign-and-lock ClinicalNote (starting a round redirects into the existing
    // editor), vitals reuse the raw Vital + a stay-scoped read, orders reuse the structured Order. show
    // = patient.view (read-logged); writes = the existing clinical gates (encounter.manage / note.write
    // / order.manage). No charge (billing is G6). Raw vitals only — no computed acuity. String-id {stay}.
    Route::get('/hospital/admissions/{stay}/chart', [BedsideChartController::class, 'show'])->name('hospital.admissions.chart');
    Route::post('/hospital/admissions/{stay}/rounds', [BedsideChartController::class, 'startRound'])->name('hospital.admissions.rounds.start');
    Route::post('/hospital/admissions/{stay}/vitals', [BedsideChartController::class, 'recordVital'])->name('hospital.admissions.vitals');
    Route::post('/hospital/admissions/{stay}/orders', [BedsideChartController::class, 'placeOrder'])->name('hospital.admissions.orders');

    // Nursing shift handover (HOSPITAL.G5) — a NET-NEW structured SBAR artifact the outgoing nurse authors
    // for the oncoming shift, tied to the stay. Append-only (a correction is a new handover). Record-not-
    // judge: nurse-authored only, nothing computed. show = patient.view (read-logged); record = note.write
    // (the nursing roles hold it — no new permission). No charge (billing is G6). String-id {stay} (FIX.1).
    Route::get('/hospital/admissions/{stay}/handover', [HandoverController::class, 'show'])->name('hospital.admissions.handover');
    Route::post('/hospital/admissions/{stay}/handover', [HandoverController::class, 'store'])->name('hospital.admissions.handover.store');

    // Bed-to-billing (HOSPITAL.G6) — "invoice this stay": assemble the stay's accrued bed-day + service
    // charges into an invoice via the EXISTING billing engine (validate -> createDraftFromCharges -> issue),
    // which reconciles-to-the-unit. NO new billing math. billing.manage-gated; redirects to the existing
    // invoice page. String-id {stay} (FIX.1). Accrual itself is the scheduled `hospital:accrue-bed-days`.
    Route::post('/hospital/admissions/{stay}/invoice', [BedBillingController::class, 'invoice'])->name('hospital.admissions.invoice');

    // Discharge summary + closed-episode view (HOSPITAL.G7) — the Phase-1 close-out. `show` renders the
    // closed stay (LOS + disposition + summary + the referenced ADT/rounds/handovers/invoice records),
    // read-logged, `patient.view`. Sign-and-lock: `save` a draft (`note.write`) then `finalize` (`note.sign`)
    // → immutable. String-id {stay} (FIX.1). Additive to the ADT flow — G2's discharge is untouched.
    Route::get('/hospital/admissions/{stay}/discharge-summary', [DischargeSummaryController::class, 'show'])->name('hospital.admissions.discharge-summary');
    Route::post('/hospital/admissions/{stay}/discharge-summary', [DischargeSummaryController::class, 'save'])->name('hospital.admissions.discharge-summary.save');
    Route::post('/hospital/admissions/{stay}/discharge-summary/finalize', [DischargeSummaryController::class, 'finalize'])->name('hospital.admissions.discharge-summary.finalize');

    // Pharmacy / medication management (PHARMACY.G1) — the tenant-authored formulary admin surface, gated
    // `formulary.manage` (the pharmacist authors the tenant's OWN med list; NO licensed drug data). Orders/
    // eMAR/dispensing are later pharmacy gates. String-id {item} (FIX.1).
    Route::get('/pharmacy/formulary', [FormularyController::class, 'index'])->name('pharmacy.formulary');
    Route::post('/pharmacy/formulary', [FormularyController::class, 'store'])->name('pharmacy.formulary.store');
    Route::post('/pharmacy/formulary/{item}/deactivate', [FormularyController::class, 'deactivate'])->name('pharmacy.formulary.deactivate');

    // Medication orders (PHARMACY.G2) — the prescribing surface for a patient (+ optional inpatient stay).
    // Read `patient.view` (read-logged); prescribing + lifecycle transitions `medication.prescribe`. The
    // order CALLS the medication-safety seam (advisory, null-object today); NO eMAR/dispensing yet. String-id.
    Route::get('/pharmacy/patients/{patient}/medications', [MedicationOrderController::class, 'index'])->name('pharmacy.patient-medications');
    Route::post('/pharmacy/patients/{patient}/medications', [MedicationOrderController::class, 'store'])->name('pharmacy.patient-medications.store');
    Route::post('/pharmacy/medication-orders/{order}/transition', [MedicationOrderController::class, 'transition'])->name('pharmacy.medication-orders.transition');

    // eMAR (PHARMACY.G3) — the medication administration record. `index` shows the due worklist (active
    // orders) + the MAR (given/held/refused); `administer` records against a G2 order (append-only, calls
    // the safety seam's checkAdministration — advisory/null-object today). Read `patient.view` (read-logged);
    // recording `note.write` (the nursing clinical-write permission). String-id. NO dispensing yet.
    Route::get('/pharmacy/patients/{patient}/emar', [MedicationAdministrationController::class, 'index'])->name('pharmacy.patient-emar');
    Route::post('/pharmacy/medication-orders/{order}/administer', [MedicationAdministrationController::class, 'record'])->name('pharmacy.medication-orders.administer');

    // Dispensing + inventory (PHARMACY.G4). Inventory (stock + receive/adjust + movements) is tenant-level,
    // gated `dispense.manage`. Dispensing against a G2 order (concurrency-safe stock decrement) is
    // patient-scoped (read `patient.view`; dispense `dispense.manage`). String-id. NO billing yet (G5).
    Route::get('/pharmacy/inventory', [InventoryController::class, 'index'])->name('pharmacy.inventory');
    Route::post('/pharmacy/inventory/receive', [InventoryController::class, 'receive'])->name('pharmacy.inventory.receive');
    Route::post('/pharmacy/inventory/{stock}/adjust', [InventoryController::class, 'adjust'])->name('pharmacy.inventory.adjust');
    Route::get('/pharmacy/patients/{patient}/dispensing', [DispensingController::class, 'index'])->name('pharmacy.patient-dispensing');
    Route::post('/pharmacy/medication-orders/{order}/dispense', [DispensingController::class, 'dispense'])->name('pharmacy.medication-orders.dispense');

    // Pharmacy pricing (PHARMACY.G5) — set a med's price as a tenant-authored TariffItem in the existing
    // tariff store (integer minor units; NO licensed pricing). A dispensed priced med accrues a Charge through
    // the existing engine → invoice → reconciles-to-the-unit. Gated `billing.manage`. String-id {item}.
    Route::get('/pharmacy/pricing', [PricingController::class, 'index'])->name('pharmacy.pricing');
    Route::post('/pharmacy/pricing/{item}', [PricingController::class, 'set'])->name('pharmacy.pricing.set');

    // Surgery — the OR case board + a case detail that drives the legal-only lifecycle (SURGERY.G2), records
    // the team + the anesthetist-ASSIGNED ASA, and authors op notes by REUSING the sign-and-lock note editor.
    // Managing a case is `surgery.manage`; authoring a note is `note.write`. String-id {case} (FIX.1).
    Route::get('/surgery/cases', [SurgicalCaseController::class, 'index'])->name('surgery.cases');
    Route::post('/surgery/cases', [SurgicalCaseController::class, 'store'])->name('surgery.cases.store');
    Route::get('/surgery/cases/{case}', [SurgicalCaseController::class, 'show'])->name('surgery.cases.show');
    Route::post('/surgery/cases/{case}/transition', [SurgicalCaseController::class, 'transition'])->name('surgery.cases.transition');
    Route::post('/surgery/cases/{case}/team', [SurgicalCaseController::class, 'team'])->name('surgery.cases.team');
    Route::post('/surgery/cases/{case}/anesthesia', [SurgicalCaseController::class, 'anesthesia'])->name('surgery.cases.anesthesia');
    Route::post('/surgery/cases/{case}/notes', [SurgicalCaseController::class, 'startNote'])->name('surgery.cases.notes');

    // WHO Surgical Safety Checklist (SURGERY.G3) — the three-phase checklist the team COMPLETES, RECORDED not
    // enforced: it never blocks/gates the case. Read + confirm are `note.write` (the surgical team). {case} string-id.
    Route::get('/surgery/cases/{case}/checklist', [SurgicalChecklistController::class, 'show'])->name('surgery.cases.checklist');
    Route::post('/surgery/cases/{case}/checklist', [SurgicalChecklistController::class, 'confirm'])->name('surgery.cases.checklist.confirm');

    // Surgical consumables + implant tracking (SURGERY.G4). Inventory (catalog + stock + receive/adjust + the
    // lot/UDI recall lookup) is `surgery.manage`; recording usage / implant placement is `note.write` (the
    // team). Stock decrement is concurrency-safe; implant lot/serial/UDI is traceability (a record, not a verdict).
    Route::get('/surgery/inventory', [SurgicalInventoryController::class, 'index'])->name('surgery.inventory');
    Route::post('/surgery/inventory/items', [SurgicalInventoryController::class, 'createItem'])->name('surgery.inventory.items');
    Route::post('/surgery/inventory/receive', [SurgicalInventoryController::class, 'receive'])->name('surgery.inventory.receive');
    Route::post('/surgery/inventory/adjust/{stock}', [SurgicalInventoryController::class, 'adjust'])->name('surgery.inventory.adjust');
    Route::get('/surgery/cases/{case}/supplies', [CaseSuppliesController::class, 'show'])->name('surgery.cases.supplies');
    Route::post('/surgery/cases/{case}/supplies/use', [CaseSuppliesController::class, 'recordUsage'])->name('surgery.cases.supplies.use');
    Route::post('/surgery/cases/{case}/supplies/implant', [CaseSuppliesController::class, 'placeImplant'])->name('surgery.cases.supplies.implant');

    // Surgical billing (SURGERY.G5) — set tenant-authored prices (TariffItems) + capture a case's charges
    // (procedure + theatre-time + consumables/implants) through the EXISTING engine → invoice
    // (reconciles-to-the-unit). Gated `billing.manage` (the billing office); NO new billing math. {case} string-id.
    Route::get('/surgery/pricing', [SurgicalPricingController::class, 'index'])->name('surgery.pricing');
    Route::post('/surgery/pricing/item/{item}', [SurgicalPricingController::class, 'setItem'])->name('surgery.pricing.item');
    Route::post('/surgery/pricing/procedure', [SurgicalPricingController::class, 'setProcedure'])->name('surgery.pricing.procedure');
    Route::post('/surgery/pricing/theatre-time', [SurgicalPricingController::class, 'setTheatreTime'])->name('surgery.pricing.theatre-time');
    Route::get('/surgery/cases/{case}/billing', [SurgicalBillingController::class, 'show'])->name('surgery.cases.billing');
    Route::post('/surgery/cases/{case}/billing/charge', [SurgicalBillingController::class, 'charge'])->name('surgery.cases.billing.charge');
    Route::post('/surgery/cases/{case}/billing/invoice', [SurgicalBillingController::class, 'invoice'])->name('surgery.cases.billing.invoice');

    // Emergency Department triage (ED.G2) — the triage nurse's arrival assessment on an EdVisit: the presenting
    // complaint, RAW vitals, and the nurse-ASSIGNED acuity (recorded verbatim, never computed). Viewing is
    // `patient.view` (read-logged); recording is `triage.record`. {visit} string-id (FIX.1). The "suggestion"
    // area is wired to the empty triage-acuity seam (shows nothing today). A computed acuity is a non-goal.
    Route::get('/ed/visits/{visit}/triage', [EdTriageController::class, 'show'])->name('ed.visits.triage.show');
    Route::post('/ed/visits/{visit}/triage', [EdTriageController::class, 'store'])->name('ed.visits.triage.store');

    // The ED tracking board (ED.G3) — the live cockpit of active ED visits + their flow state + the RECORDED
    // triage acuity + facts. Reuses the ward-board idiom over the EdVisit flow. Gated `ed.manage` (ED staff);
    // the board's flow action advances the visit via the EXISTING service (the G1 legal transitions). FENCE:
    // facts + recorded acuity only — NO computed priority ranking. {visit} string-id (FIX.1).
    Route::get('/ed/board', [EdBoardController::class, 'index'])->name('ed.board');
    Route::post('/ed/visits/{visit}/transition', [EdBoardController::class, 'transition'])->name('ed.board.transition');

    // Onboarding/migration: generic CSV patient import (RBAC 'data.import' enforced
    // in each controller action). Mandatory dry-run before commit.
    Route::get('/imports', [ImportBatchController::class, 'index'])->name('import.index');
    Route::get('/imports/create', [ImportBatchController::class, 'create'])->name('import.create');
    Route::post('/imports', [ImportBatchController::class, 'store'])->name('import.store');
    Route::get('/imports/{batch}', [ImportBatchController::class, 'show'])->name('import.show');
    Route::post('/imports/{batch}/mapping', [ImportBatchController::class, 'mapping'])->name('import.mapping');
    Route::post('/imports/{batch}/validate', [ImportBatchController::class, 'validateBatch'])->name('import.validate');
    Route::post('/imports/{batch}/commit', [ImportBatchController::class, 'commit'])->name('import.commit');

    // Staff billing UI (CLINIC.W6): invoice worklist + detail, AR aging, credit
    // notes. READS gate on 'billing.view', WRITES (issue / credit note) on
    // 'billing.manage' — all money math stays inside the tested billing services.
    Route::get('/billing/invoices', [InvoiceController::class, 'index'])->name('billing.invoices.index');
    Route::get('/billing/aging', AgingController::class)->name('billing.aging');
    Route::get('/billing/credit-notes', [CreditNoteController::class, 'index'])->name('billing.credit-notes.index');
    Route::get('/billing/credit-notes/{invoice}', [CreditNoteController::class, 'show'])->name('billing.credit-notes.show');
    Route::get('/billing/invoices/{invoice}', [InvoiceController::class, 'show'])->name('billing.invoices.show');
    Route::get('/billing/invoices/{invoice}/pdf', [InvoiceController::class, 'download'])->name('billing.invoices.download');
    Route::post('/billing/invoices/{invoice}/issue', [InvoiceController::class, 'issue'])->name('billing.invoices.issue');
    Route::post('/billing/invoices/{invoice}/credit-note', [InvoiceController::class, 'creditNote'])->name('billing.invoices.credit-note');

    // Staff billing UI part 2 (CLINIC.W7): payments + allocations, new-invoice-from-
    // charges, dunning worklist. READS gate 'billing.view', WRITES 'billing.manage' —
    // every money movement stays inside PaymentService / IssueService / DunningService.
    // (Static path segments are registered before their {param} siblings so no
    // wildcard route captures /record or /new-invoice.)
    Route::get('/billing/payments', [PaymentController::class, 'index'])->name('billing.payments.index');
    Route::get('/billing/payments/record', [PaymentController::class, 'create'])->name('billing.payments.create');
    Route::post('/billing/payments', [PaymentController::class, 'store'])->name('billing.payments.store');
    Route::get('/billing/payments/{payment}', [PaymentController::class, 'show'])->name('billing.payments.show');
    Route::post('/billing/payments/{payment}/allocate', [PaymentController::class, 'allocate'])->name('billing.payments.allocate');
    Route::post('/billing/payments/{payment}/reverse', [PaymentController::class, 'reverse'])->name('billing.payments.reverse');
    Route::get('/billing/new-invoice', [InvoiceDraftController::class, 'create'])->name('billing.invoices.create');
    Route::post('/billing/new-invoice', [InvoiceDraftController::class, 'store'])->name('billing.invoices.store');
    Route::get('/billing/dunning', [DunningController::class, 'index'])->name('billing.dunning.index');
    Route::post('/billing/dunning/run', [DunningController::class, 'run'])->name('billing.dunning.run');

    // Reporting dashboard — the thin facts-only surface over ReportingService::summary.
    Route::get('/reporting', ReportingDashboardController::class)->name('reporting.dashboard');

    // Governance oversight + AI approval queue (CLINIC.W9) — read/act windows onto tested
    // backends, no new autonomy or audit-mutation. Governance is READ-ONLY (audit.view): the
    // only POST re-runs the existing chain verification and writes nothing. The approval
    // queue (ai.manage) approves/rejects ONLY through AiCore's ApprovalQueue — the same path
    // the eval harness locks; the tool's own permission is re-checked server-side so the caps
    // bind. Actions resolve by string id (FIX.1) so cross-tenant ids fail closed as 404.
    Route::get('/governance', [GovernanceDashboardController::class, 'index'])->name('governance.dashboard');
    Route::post('/governance/verify-chain', [GovernanceDashboardController::class, 'verifyChain'])->name('governance.verify-chain');
    Route::get('/governance/approvals', [AiApprovalQueueController::class, 'index'])->name('governance.approvals.index');
    Route::post('/governance/approvals/{id}/approve', [AiApprovalQueueController::class, 'approve'])->name('governance.approvals.approve');
    Route::post('/governance/approvals/{id}/reject', [AiApprovalQueueController::class, 'reject'])->name('governance.approvals.reject');

    // KB admin (CLINIC.W10) — CRUD over the front-desk agent's grounding source. ai.manage-gated
    // (same governance area). This curates CONTENT only: the agent's grounding/fence is unchanged
    // and a deactivated article stops being grounded on (KbRetriever filters is_active=true). Ids
    // resolve by string (FIX.1) → cross-tenant/missing = 404.
    Route::get('/governance/kb', [KbArticleController::class, 'index'])->name('governance.kb.index');
    Route::post('/governance/kb', [KbArticleController::class, 'store'])->name('governance.kb.store');
    Route::post('/governance/kb/{id}/update', [KbArticleController::class, 'update'])->name('governance.kb.update');
    Route::post('/governance/kb/{id}/toggle', [KbArticleController::class, 'toggle'])->name('governance.kb.toggle');

    // Staff telehealth join (CLINIC.W10) — the clinician side of the SAME sessions the portal
    // patient joins (W3). encounter.manage-gated; the staff token comes from the EXISTING
    // TelehealthService (recording-disabled, short-lived, never stored/logged) — no new telehealth
    // logic, "not recorded" discipline preserved.
    Route::get('/telehealth', [StaffTelehealthController::class, 'index'])->name('telehealth.index');
    Route::post('/telehealth/{session}/token', [StaffTelehealthController::class, 'token'])->name('telehealth.token');

    // Kiosk device provisioning (admin.manage enforced in the controller). The
    // plaintext token is shown once at issue.
    Route::get('/admin/kiosks', [KioskDeviceController::class, 'index'])->name('admin.kiosks.index');
    Route::post('/admin/kiosks', [KioskDeviceController::class, 'issue'])->name('admin.kiosks.issue');
    Route::post('/admin/kiosks/revoke', [KioskDeviceController::class, 'revoke'])->name('admin.kiosks.revoke');

    // Practice settings + Roles & access (CLINIC.W8). Both gate admin.manage in the
    // controller; settings write through SettingsService, role assignment through the
    // audited RoleAssignment path — no domain logic here.
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::post('/settings', [SettingsController::class, 'update'])->name('settings.update');
    Route::post('/settings/profile', [SettingsController::class, 'updateProfile'])->name('settings.profile.update');
    Route::get('/admin/roles', [UserRoleController::class, 'index'])->name('admin.roles.index');
    Route::post('/admin/roles/assign', [UserRoleController::class, 'assign'])->name('admin.roles.assign');

    // Branch management (CLINIC.W8b) — app-layer controller (deactivation guard spans
    // Platform + Scheduling); soft-deactivate blocked when future appointments exist.
    Route::get('/admin/branches', [BranchController::class, 'index'])->name('admin.branches.index');
    Route::post('/admin/branches', [BranchController::class, 'store'])->name('admin.branches.store');
    Route::post('/admin/branches/{branch}/update', [BranchController::class, 'update'])->name('admin.branches.update');
    Route::post('/admin/branches/{branch}/hours', [BranchController::class, 'hours'])->name('admin.branches.hours');
    Route::post('/admin/branches/{branch}/deactivate', [BranchController::class, 'deactivate'])->name('admin.branches.deactivate');
    Route::post('/admin/branches/{branch}/activate', [BranchController::class, 'activate'])->name('admin.branches.activate');

    // Bookable-resource CRUD (CLINIC.W8c) — app-layer controller (deactivation guard spans
    // Scheduling's Resource + its appointments); soft-deactivate blocked when future
    // appointments exist. Managed on the /admin/branches screen (resources belong to a branch).
    Route::post('/admin/branches/{branch}/resources', [ResourceController::class, 'store'])->name('admin.resources.store');
    Route::post('/admin/resources/{resource}/update', [ResourceController::class, 'update'])->name('admin.resources.update');
    Route::post('/admin/resources/{resource}/deactivate', [ResourceController::class, 'deactivate'])->name('admin.resources.deactivate');
    Route::post('/admin/resources/{resource}/activate', [ResourceController::class, 'activate'])->name('admin.resources.activate');
});

// Self check-in KIOSK (P0P.G7): unauthenticated but scoped to a branch by the
// kiosk device token. The kiosk-device middleware sets the tenant context; these
// routes expose ONLY resolve + check-in + own-contact-update, never clinical data
// or patient search. Code entry is rate-limited against brute-force.
Route::prefix('kiosk/{kioskToken}')
    ->middleware('kiosk-device')
    ->name('kiosk.')
    ->group(function () {
        Route::get('/', [KioskCheckInController::class, 'page'])->name('check-in.page');
        Route::post('/resolve', [KioskCheckInController::class, 'resolve'])
            ->middleware('throttle:10,1')->name('resolve');
        Route::post('/check-in', [KioskCheckInController::class, 'checkIn'])->name('check-in');
        Route::post('/contact', [KioskCheckInController::class, 'updateContact'])->name('contact');
    });

Route::prefix('book/{tenant:slug}')
    ->middleware('throttle:20,1')
    ->name('public.booking.')
    ->group(function () {
        Route::get('/', [PublicBookingController::class, 'index'])->name('index');
        Route::post('/slots', [PublicBookingController::class, 'slots'])->name('slots');
        Route::post('/', [PublicBookingController::class, 'store'])->name('store');
    });

Route::prefix('portal')->name('portal.')->group(function () {
    Route::get('/login', [PortalAuthController::class, 'showLogin'])->name('login');
    Route::post('/accept-invite', [PortalAuthController::class, 'acceptInvite'])->name('accept-invite');
    Route::post('/login', [PortalAuthController::class, 'login'])->name('login.attempt');
    Route::post('/logout', [PortalAuthController::class, 'logout'])->name('logout');

    // Every portal page: portal tenant + patient guard + portal.access consent
    // (fail-closed; withdrawing consent locks the portal on the next request).
    Route::middleware(['portal-tenant', 'portal-auth', 'portal-consent'])->group(function () {
        Route::get('/', PortalHomeController::class)->name('home');

        Route::get('/appointments', [PortalAppointmentController::class, 'index'])->name('appointments');
        Route::post('/appointments/slots', [PortalAppointmentController::class, 'slots'])->name('appointments.slots');
        Route::post('/appointments', [PortalAppointmentController::class, 'store'])->name('appointments.store');
        Route::post('/appointments/cancel', [PortalAppointmentController::class, 'cancel'])->name('appointments.cancel');

        // Self check-in from the authenticated portal (P0P.G7).
        Route::post('/check-in', [PortalCheckInController::class, 'checkIn'])->name('check-in');
        Route::post('/check-in/contact', [PortalCheckInController::class, 'updateContact'])->name('check-in.contact');

        Route::get('/documents', [PortalDocumentController::class, 'index'])->name('documents.index');
        Route::get('/documents/{document}', [PortalDocumentController::class, 'show'])->name('documents.show');

        Route::get('/messages', [PortalMessageController::class, 'index'])->name('messages');
        Route::post('/messages', [PortalMessageController::class, 'store'])->name('messages.store');

        Route::get('/invoices', [PortalInvoiceController::class, 'index'])->name('invoices');
        Route::get('/invoices/{invoice}/pdf', [PortalInvoiceController::class, 'download'])->name('invoices.download');

        Route::get('/consents', [PortalConsentController::class, 'index'])->name('consents');
        Route::post('/consents/withdraw', [PortalConsentController::class, 'withdraw'])->name('consents.withdraw');

        Route::get('/telehealth', [PortalTelehealthController::class, 'index'])->name('telehealth');
        Route::post('/telehealth/{session}/token', [PortalTelehealthController::class, 'token'])->name('telehealth.token');

        // Dental treatment plan (DENTAL.G5) — READ-ONLY view of the patient's own plans (proposed
        // onward). Display only — no lifecycle actions, no payment (PSP deferred).
        Route::get('/treatment-plan', [PortalTreatmentPlanController::class, 'index'])->name('treatment-plan');
    });
});

Route::post('/portal/invitations', PortalInvitationController::class)
    ->middleware('auth')
    ->name('portal.invitations.store');
