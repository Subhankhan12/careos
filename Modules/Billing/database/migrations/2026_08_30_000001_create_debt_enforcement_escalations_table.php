<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ARDETAIL.P6 — the APPEND-ONLY record of a debt-enforcement (Swiss *Betreibung*) escalation.
 *
 * This is a LEGAL PROCEEDING against a patient account, and the row is its provenance: WHO escalated
 * (a real `users` row — `initiated_by` is NOT NULL with a foreign key, so no system/agent actor can
 * ever be recorded as the initiator), WHEN, WHY (a required reason), on WHAT (the outstanding at the
 * moment of escalation and the terminal dunning stage that made the account eligible), and that the
 * operator EXPLICITLY CONFIRMED it (`confirmed_at`).
 *
 * APPEND-ONLY (ORM guards + DB triggers, the `invoice_adjustments` recipe): a withdrawal is a NEW row
 * with `action = withdrawn` pointing at the escalation it supersedes — never an edit or a delete. A
 * legal action's history can therefore never be rewritten.
 *
 * Moments are DATETIME, never TIMESTAMP (P0P.G15): MariaDB 10.4 gives the first non-nullable TIMESTAMP
 * an implicit ON UPDATE CURRENT_TIMESTAMP, which would silently rewrite a recorded legal moment.
 *
 * THE FENCE: escalation is operator-only (`billing.escalate` — deliberately NARROWER than
 * `billing.manage`, which charge-capturing clinical roles also hold) and the AI agent has NO path to
 * this table at all — no tool, no service reference, no scheduled job. "0 auto-escalated" is
 * structural, not a displayed number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debt_enforcement_escalations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('patient_id');
            $table->string('action'); // initiated | withdrawn
            $table->ulid('supersedes_escalation_id')->nullable();
            // The account outstanding at the moment of escalation (provenance, integer minor).
            $table->bigInteger('outstanding_minor');
            $table->string('currency', 3);
            // The terminal dunning stage the account had reached — the eligibility evidence.
            $table->unsignedSmallInteger('dunning_stage');
            $table->text('reason');
            $table->string('reference')->nullable(); // official Betreibungsbegehren reference, when known
            // The OPERATOR. Not nullable: every escalation names a human being.
            $table->foreignId('initiated_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('confirmed_at'); // the explicit operator confirmation
            $table->dateTime('initiated_at');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->restrictOnDelete();
            $table->foreign('supersedes_escalation_id')->references('id')->on('debt_enforcement_escalations')->restrictOnDelete();

            $table->index(['tenant_id', 'patient_id']);
            $table->index(['tenant_id', 'action']);
            $table->unique(['supersedes_escalation_id']); // an escalation is withdrawn at most once
        });

        // A legal action is never edited or deleted — a withdrawal is a NEW row.
        DB::unprepared(
            "CREATE TRIGGER debt_enforcement_escalations_no_update BEFORE UPDATE ON debt_enforcement_escalations\n".
            "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'debt_enforcement_escalations is append-only: UPDATE is forbidden';"
        );
        DB::unprepared(
            "CREATE TRIGGER debt_enforcement_escalations_no_delete BEFORE DELETE ON debt_enforcement_escalations\n".
            "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'debt_enforcement_escalations is append-only: DELETE is forbidden';"
        );
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS debt_enforcement_escalations_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS debt_enforcement_escalations_no_delete');
        Schema::dropIfExists('debt_enforcement_escalations');
    }
};
