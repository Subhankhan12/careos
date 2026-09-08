<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QA-FIX.7a (P7-C1, D-211) — the triage record gains the ACTOR who entered it.
 *
 * `ed_triages` carried exactly ONE person: `triaged_by`, a `staff_profiles` id taken straight from a
 * dropdown whose first entry the form pre-selected. Driven in Phase 7 as `yusuf.demir` (the triage
 * nurse), the resulting record read "Triaged by Beat Suter" — a `surgical_scheduler` who was not
 * present, is not a clinician, and merely sorts first alphabetically. Nothing failed and nothing was
 * refused: the write succeeded and left the wrong fact behind. There was no column anywhere on the
 * clinical record naming who actually typed it.
 *
 * THIS IS QA-FIX.6b's REMEDY, UNCHANGED — two people, two columns, and neither may stand in for the
 * other (the QA-FIX.2a / D-195 rule):
 *   - `triaged_by`  — the NURSE whose assessment this is (a `staff_profiles` id, selected). Clinical
 *                     provenance, and what the column has always meant. UNCHANGED.
 *   - `recorded_by` — the ACTOR who entered it (a `users` id, taken from the authenticated user and
 *                     NEVER submitted by the client). This is the column that did not exist.
 *
 * WHY A COLUMN HERE AND NOT AN EVENT ROW. `ed_visit_events.performed_by` already records the actor for
 * a flow transition — but a triage only transitions the visit on the FIRST triage
 * (`TriageService::record` moves `arrived → triaged` only when the visit is still `arrived`). A
 * RE-TRIAGE, the exact case this module is append-only in order to support, appends no event at all, so
 * for every re-triage there would be no actor on the clinical record. The assessment therefore carries
 * its own recorder, as `surgical_case_anesthesia_assessments` does.
 *
 * NULLABLE, DELIBERATELY. Rows written before this column existed have no recorded actor and are not
 * rewritten — inventing one would be a fabricated attribution, which is the defect, not the fix. The
 * actor for those rows survives in the append-only audit ledger (`ed_triage.recorded`), which is where
 * Phase 7 recovered it. Scope recorded as D-211, following D-193 / D-197 / D-202.
 *
 * The table's append-only `SIGNAL '45000'` triggers are untouched: they guard row UPDATE/DELETE, and
 * DDL does not fire them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ed_triages', function (Blueprint $table): void {
            // The ACTOR — authenticated, never request-sourced. Nullable for pre-existing rows only.
            $table->foreignId('recorded_by')->nullable()->after('triaged_by')
                ->constrained('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ed_triages', function (Blueprint $table): void {
            $table->dropForeign(['recorded_by']);
            $table->dropColumn('recorded_by');
        });
    }
};
