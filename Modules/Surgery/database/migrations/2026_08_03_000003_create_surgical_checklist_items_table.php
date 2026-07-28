<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SURGERY.G3 — the APPEND-ONLY WHO-checklist completion log. One immutable row per confirmation: a team member
 * confirms (or, correcting, un-confirms) an item, with who + when + an optional note. A correction is a NEW
 * row (with a reason), never an edit — the completion history is preserved (model guards + `SIGNAL '45000'` DB
 * triggers, the `surgical_case_events` recipe). The CURRENT state of an item is its latest row.
 *
 * `phase` + `label` are SNAPSHOTTED from the template at confirmation (a later template edit never rewrites a
 * past record). `template_item_id` is a SOFT ref (no FK — the template is editable). Patient-scoped.
 *
 * ELECTRIC FENCE (the crux): this table RECORDS what the team confirmed. There is deliberately NO
 * verdict / passed / safe / pass_fail / compliant / score column — CareOS computes no safety judgment, and
 * nothing here gates the surgery (a factual "checked / total" count is derived on read, never a verdict).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surgical_checklist_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('surgical_checklist_id');
            $table->ulid('patient_id');           // denormalized for patient-scoped read logging
            $table->ulid('template_item_id')->nullable(); // SOFT ref to the template item confirmed (no FK)
            $table->string('phase');              // snapshot: sign_in / time_out / sign_out
            $table->string('label');              // snapshot of the item text at confirmation
            $table->boolean('checked');           // the team member's recorded FACT (checked / not-checked)
            $table->foreignId('confirmed_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('confirmed_at');
            $table->string('note', 500)->nullable(); // optional note / correction reason (matches the max:500 rule)
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('surgical_checklist_id')->references('id')->on('surgical_checklists')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();

            $table->index(['tenant_id', 'surgical_checklist_id'], 'surg_checklist_item_list_idx');
            $table->index(['tenant_id', 'patient_id', 'confirmed_at'], 'surg_checklist_item_patient_idx');
        });

        // Append-only: a checklist confirmation is an immutable record of fact.
        DB::unprepared(<<<'SQL'
CREATE TRIGGER surgical_checklist_items_no_update BEFORE UPDATE ON surgical_checklist_items
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'surgical_checklist_items are append-only: UPDATE is forbidden';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER surgical_checklist_items_no_delete BEFORE DELETE ON surgical_checklist_items
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'surgical_checklist_items are append-only: DELETE is forbidden';
END
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS surgical_checklist_items_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS surgical_checklist_items_no_delete');
        Schema::dropIfExists('surgical_checklist_items');
    }
};
