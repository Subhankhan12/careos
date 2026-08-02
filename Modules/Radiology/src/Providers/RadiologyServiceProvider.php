<?php

namespace Modules\Radiology\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Radiology\Contracts\ImagingConnectivity;
use Modules\Radiology\Services\NullImagingConnectivity;

/**
 * The Radiology / RIS vertical — **Phase 4** of the phased hospital build (the LAST hospital phase). RAD.G1
 * ships the FOUNDATION only: the peer module, the tenant-authored imaging **exam catalog** (a `RadiologyExam`
 * overlay on the EXISTING Clinical `OrderableItem`, `category='imaging'`), radiology RBAC, and — CREATED here,
 * EMPTY — the **`ImagingConnectivity` (PACS/DICOM) seam** (per docs/HOSPITAL-PHASE4-RADIOLOGY-MAP.md). No order
 * entry (G2 — reuses Clinical `Order`), study record + worklist (G3), report (G4 — reuses the sign-and-lock
 * `ClinicalNote`), or billing (G5).
 *
 * **Radiology REUSES Clinical — it does NOT re-create it.** An imaging order is a Clinical `Order` (the
 * `imaging` category + a `specimen_or_modality` field already exist); a report is a sign-and-lock
 * `ClinicalNote`; an uploaded still is a `Document` (the DENTAL.G8 recipe). Unlike Lab (whose `LabConnectivity`
 * seam already lived in Clinical), NO imaging seam existed — so this gate CREATES `ImagingConnectivity` and
 * binds it to its null-object here (the `LabConnectivity`/`TriageAcuityProvider`/`MedicationSafetyProvider`
 * precedent), so a certified PACS/DICOM partner can be bound in its place later WITHOUT touching any consumer.
 *
 * The DICOM/PACS integration itself (native DICOM storage, MWL push, a diagnostic viewer, PACS retrieval) is
 * the partner-gated RAD.G6 — SEAM-STUBBED, NOT built; a homemade DICOM/PACS stack is a PERMANENT non-goal. THE
 * FENCE: the seam never interprets an image (no CAD/auto-read/finding — "AI radiology" is a hard non-goal).
 * Cross-module audit composition lives in the app layer, so this module stays free of Audit — the
 * ED/Surgery/Pharmacy/Lab posture.
 */
class RadiologyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // THE IMAGING-CONNECTIVITY (PACS/DICOM) SEAM. CareOS performs no DICOM/PACS integration itself — native
        // DICOM storage, the modality-worklist push, a diagnostic viewer, and PACS retrieval are the defining
        // RIS value and are partner-gated (a homemade DICOM/PACS stack is a permanent non-goal). The null-object
        // is a no-op (transmit) / "not available" (ingest); swap the binding for a certified partner later, no
        // consumer changes. The seam never interprets an image (the AI-imaging fence).
        $this->app->bind(ImagingConnectivity::class, NullImagingConnectivity::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }
}
