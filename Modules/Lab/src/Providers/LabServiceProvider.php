<?php

namespace Modules\Lab\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * The Laboratory / LIS vertical — **Phase 3** of the phased hospital build. LAB.G1 ships the FOUNDATION only:
 * the module, the tenant-authored lab test catalog (a thin `LabTest` overlay on the EXISTING Clinical
 * `OrderableItem`), lab RBAC, and the FORMALIZED `LabConnectivity` seam (per docs/HOSPITAL-PHASE3-LAB-MAP.md).
 * No order entry (G2 — reuses Clinical `Order`), specimen tracking (G3), result entry (G4), routing (G5), or
 * billing (G6).
 *
 * **Lab REUSES Clinical — it does NOT re-create it.** A lab order is a Clinical `Order`; a lab result is a
 * Clinical `OrderResult` (append-only, raw, fence already built); and — crucially — the `LabConnectivity` seam
 * (`transmit` / `ingestResult`) **already exists in Clinical** and is **already wired** into
 * `OrderService::place()` (bound to `ManualLabConnectivity`, the manual no-op). This module therefore binds NO
 * new seam here — it CONSUMES Clinical's. The seam's two paths are formalized in the Clinical contract's
 * docblocks: the MANUAL path (a human enters an `OrderResult` `source=manual` — built today) and the IMPORTED
 * path (a future CERTIFIED HL7/analyzer partner implements `ingestResult` → appends an `OrderResult`
 * `source=imported`, NEVER interpreted — the P0P.G11 discipline). A homemade HL7/analyzer client is the
 * partner-gated LAB.G7 (SEAM-STUBBED, NOT built). Cross-module audit composition lives in the app layer, so
 * this module stays free of Audit — the ED/Surgery/Pharmacy posture.
 */
class LabServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }
}
