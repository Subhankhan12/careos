<?php

namespace Modules\Surgery\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Surgery\Console\AttemptBookSlotCommand;
use Modules\Surgery\Console\AttemptUseItemCommand;

/**
 * The operating-theatre / surgery vertical — **Phase 5** of the phased hospital build. SURGERY.G1 ships the
 * FOUNDATION only: the module, the theatre + theatre-scheduling (a NET-NEW `TheatreSlot` that reuses the
 * booking/bed overlap-lock INVARIANT, per docs/HOSPITAL-PHASE5-SURGERY-MAP.md §2.1), a NET-NEW `SurgicalCase`
 * (scheduled status), and OR RBAC. No case lifecycle (G2), checklist (G4), consumables (G5), or billing (G6).
 *
 * Cross-module audit composition (the theatre / slot / case model hooks) lives in the app layer, so this
 * module stays free of Audit — the Dental / Hospital / Pharmacy posture. The inpatient stay-link is a soft
 * `stay_id` composed app-layer, not a direct Hospital dependency. The intra-op device-data / surgical-risk
 * seam (the `LabConnectivity` / `MedicationSafetyProvider` precedent) arrives with its consumer in a later
 * gate — there is nothing to invoke it in G1.
 */
class SurgeryServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                AttemptBookSlotCommand::class, // the parallel-hammer theatre booking (concurrency test only)
                AttemptUseItemCommand::class,  // the parallel-hammer stock decrement (concurrency test only)
            ]);
        }
    }
}
