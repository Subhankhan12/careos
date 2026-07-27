<?php

namespace Modules\Pharmacy\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Pharmacy\Contracts\MedicationSafetyProvider;
use Modules\Pharmacy\Services\NullMedicationSafetyProvider;

/**
 * Pharmacy / medication-management vertical (Phase 2 of the phased hospital build). PHARMACY.G1 ships the
 * FOUNDATION only: the tenant-authored formulary + the medication-safety SEAM (null-object) + pharmacy RBAC.
 * No orders (PHARMACY.G2), no eMAR (G3), no dispensing/inventory (G4), no billing (G5).
 *
 * The medication-safety seam is bound here to its null-object — the `LabConnectivity` precedent — so a
 * certified partner engine can later be bound in its place WITHOUT touching any consumer. Cross-module
 * audit composition lives in the app layer, so this module stays free of Audit.
 */
class PharmacyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // THE MEDICATION-SAFETY SEAM. CareOS performs no drug-interaction/dose/contraindication checking
        // itself — that is certified-partner / medical-device territory and a permanent homemade non-goal.
        // The null-object returns "no alerts" (asserts nothing); swap the binding for a licensed partner later.
        $this->app->bind(MedicationSafetyProvider::class, NullMedicationSafetyProvider::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }
}
