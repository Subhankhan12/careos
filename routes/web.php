<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
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
use Modules\Patients\Http\Controllers\PatientConsentController;
use Modules\Patients\Http\Controllers\PatientIndexController;
use Modules\Patients\Http\Controllers\PatientRegistrationController;
use Modules\Patients\Http\Controllers\PatientShowController;
use Modules\Patients\Http\Controllers\PortalAuthController;
use Modules\Patients\Http\Controllers\PortalInvitationController;
use Modules\Scheduling\Http\Controllers\DayBoardActionController;
use Modules\Scheduling\Http\Controllers\DayBoardController;
use Modules\Scheduling\Http\Controllers\PublicBookingController;

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

    Route::get('/clinical/encounters/{encounter}', EncounterShowController::class)
        ->name('clinical.encounters.show');
    Route::get('/clinical/notes/{note}', ClinicalNoteShowController::class)
        ->name('clinical.notes.show');
    Route::get('/clinical/chart/{patient}', ClinicalChartController::class)
        ->name('clinical.chart');
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
    Route::get('/login', fn () => response('Patient portal login pending UI'))->name('login');
    Route::post('/accept-invite', [PortalAuthController::class, 'acceptInvite'])->name('accept-invite');
    Route::post('/login', [PortalAuthController::class, 'login'])->name('login.attempt');

    Route::get('/', fn () => response('Patient portal pending UI'))
        ->middleware(['portal-tenant', 'portal-auth', 'portal-consent'])
        ->name('home');
    Route::get('/documents', [PortalDocumentController::class, 'index'])
        ->middleware(['portal-tenant', 'portal-auth', 'portal-consent'])
        ->name('documents.index');
    Route::get('/documents/{document}', [PortalDocumentController::class, 'show'])
        ->middleware(['portal-tenant', 'portal-auth', 'portal-consent'])
        ->name('documents.show');
});

Route::post('/portal/invitations', PortalInvitationController::class)
    ->middleware('auth')
    ->name('portal.invitations.store');
