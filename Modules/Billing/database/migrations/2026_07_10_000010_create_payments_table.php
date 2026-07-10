<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('patient_id')->nullable();
            $table->string('payer_reference')->nullable();
            $table->string('method');
            $table->bigInteger('amount_minor');
            $table->char('currency', 3)->default('EUR');
            $table->date('received_on');
            $table->string('reference')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->restrictOnDelete();

            $table->index(['tenant_id', 'patient_id']);
            $table->index(['tenant_id', 'received_on']);
        });

        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_positive CHECK (amount_minor > 0)');

        // Append-only money movement: payments are never edited or deleted.
        // Refunds are separate rows; corrections are new rows, never mutations.
        DB::unprepared(
            "CREATE TRIGGER payments_no_update BEFORE UPDATE ON payments\n".
            "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payments is append-only: UPDATE is forbidden';"
        );
        DB::unprepared(
            "CREATE TRIGGER payments_no_delete BEFORE DELETE ON payments\n".
            "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payments is append-only: DELETE is forbidden';"
        );
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS payments_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS payments_no_delete');
        Schema::dropIfExists('payments');
    }
};
