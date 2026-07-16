<?php

use App\Http\Controllers\ClinicalSummaryDraftController;
use App\Http\Controllers\ClinicalSummaryInsertController;
use App\Http\Controllers\Comms\InboxAgentController;
use App\Http\Controllers\Portal\PortalHomeController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
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
use Modules\Clinical\Http\Controllers\PortalDocumentController;
use Modules\Comms\Http\Controllers\InboxActionController;
use Modules\Comms\Http\Controllers\InboxController;
use Modules\Comms\Http\Controllers\PortalMessageController;
use Modules\Comms\Http\Controllers\PortalTelehealthController;
use Modules\Import\Http\Controllers\ImportBatchController;
use Modules\Nursing\Http\Controllers\DispatchActionController;
use Modules\Nursing\Http\Controllers\DispatchBoardController;
use Modules\Patients\Http\Controllers\PatientConsentController;
use Modules\Patients\Http\Controllers\PatientIndexController;
use Modules\Patients\Http\Controllers\PatientRegistrationController;
use Modules\Patients\Http\Controllers\PatientShowController;
use Modules\Patients\Http\Controllers\PortalAuthController;
use Modules\Patients\Http\Controllers\PortalConsentController;
use Modules\Patients\Http\Controllers\PortalInvitationController;
use Modules\Scheduling\Http\Controllers\DayBoardActionController;
use Modules\Scheduling\Http\Controllers\DayBoardController;
use Modules\Scheduling\Http\Controllers\PortalAppointmentController;
use Modules\Scheduling\Http\Controllers\PublicBookingController;
use Modules\Scheduling\Http\Controllers\WaitlistOfferController;

Route::get('/', function () {
    if (! auth()->check()) {
        return redirect()->route('login');
    }

    return redirect(auth()->user()->isSuperAdmin() ? '/admin' : '/app');
});

Route::middleware('auth')->group(function () {
    // Tenant app shell. Tenant identification + mandatory-MFA run in the web group.
    Route::get('/app', fn () => Inertia::render('App/Landing'))->name('app.landing');

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

    // Onboarding/migration: generic CSV patient import (RBAC 'data.import' enforced
    // in each controller action). Mandatory dry-run before commit.
    Route::get('/imports', [ImportBatchController::class, 'index'])->name('import.index');
    Route::get('/imports/create', [ImportBatchController::class, 'create'])->name('import.create');
    Route::post('/imports', [ImportBatchController::class, 'store'])->name('import.store');
    Route::get('/imports/{batch}', [ImportBatchController::class, 'show'])->name('import.show');
    Route::post('/imports/{batch}/mapping', [ImportBatchController::class, 'mapping'])->name('import.mapping');
    Route::post('/imports/{batch}/validate', [ImportBatchController::class, 'validateBatch'])->name('import.validate');
    Route::post('/imports/{batch}/commit', [ImportBatchController::class, 'commit'])->name('import.commit');
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
    });
});

Route::post('/portal/invitations', PortalInvitationController::class)
    ->middleware('auth')
    ->name('portal.invitations.store');
