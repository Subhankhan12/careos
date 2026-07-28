<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SURGERY.G2 — the case LIFECYCLE + anesthetist-assigned values, added to the G1 `surgical_cases`. Per
 * docs/HOSPITAL-PHASE5-SURGERY-MAP.md §2.2/§2.3. `status_reason` + the per-phase timestamps
 * (`pre_op_at`/`in_progress_at`/`completed_at`/`post_op_at`/`cancelled_at`) are FACTUAL times a human records
 * on transition — never a computed duration/grade. `asa_class` (I–VI) + `mallampati` (I–IV) are values the
 * ANESTHETIST ASSIGNS (recorded facts, with provenance `asa_assessed_by`/`asa_assessed_at`).
 *
 * ELECTRIC FENCE: there is deliberately NO computed surgical-risk / prediction / score column — a computed
 * risk score is medical-device territory (map §3), a certified-partner / non-goal; the ASA class is assigned
 * by the clinician and recorded, never computed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('surgical_cases', function (Blueprint $table): void {
            $table->string('status_reason')->nullable()->after('status'); // e.g. why a case was cancelled
            // Factual per-phase times (recorded on transition), never a computed duration/grade.
            $table->dateTime('pre_op_at')->nullable()->after('status_reason');
            $table->dateTime('in_progress_at')->nullable()->after('pre_op_at'); // incision / wheels-in
            $table->dateTime('completed_at')->nullable()->after('in_progress_at');
            $table->dateTime('post_op_at')->nullable()->after('completed_at');
            $table->dateTime('cancelled_at')->nullable()->after('post_op_at');
            // Anesthetist-ASSIGNED values (recorded facts, NOT computed). Provenance: who assigned + when.
            $table->string('asa_class')->nullable()->after('cancelled_at');   // I..VI (Roman)
            $table->string('mallampati')->nullable()->after('asa_class');     // I..IV (Roman)
            $table->ulid('asa_assessed_by')->nullable()->after('mallampati'); // the anesthetist (staff_profiles)
            $table->dateTime('asa_assessed_at')->nullable()->after('asa_assessed_by');

            $table->foreign('asa_assessed_by')->references('id')->on('staff_profiles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('surgical_cases', function (Blueprint $table): void {
            $table->dropForeign(['asa_assessed_by']);
            $table->dropColumn([
                'status_reason', 'pre_op_at', 'in_progress_at', 'completed_at', 'post_op_at', 'cancelled_at',
                'asa_class', 'mallampati', 'asa_assessed_by', 'asa_assessed_at',
            ]);
        });
    }
};
