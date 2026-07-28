<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ED.G5 — add the SOFT `stay_id` link to `ed_visits`: when an ED visit is dispositioned to ADMIT, the ED→ADT
 * handoff creates a Phase-1 inpatient `Stay` (via the EXISTING AdmissionService, app-layer) and records its id
 * here, so the episode is traceable ED→inpatient (docs/HOSPITAL-PHASE6-ED-MAP.md §2.3). Additive + nullable —
 * NO FK / NO relation (the surgery `stay_id` precedent) so ED stays arch-INDEPENDENT of Hospital; null for a
 * discharged/transferred visit or one still open. Set only on admit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ed_visits', function (Blueprint $table): void {
            $table->ulid('stay_id')->nullable()->after('disposition'); // SOFT ref to a Phase-1 Stay (no FK)
        });
    }

    public function down(): void
    {
        Schema::table('ed_visits', function (Blueprint $table): void {
            $table->dropColumn('stay_id');
        });
    }
};
