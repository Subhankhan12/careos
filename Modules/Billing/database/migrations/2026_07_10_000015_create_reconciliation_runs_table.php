<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->string('period', 7); // YYYY-MM
            $table->timestamp('ran_at');
            $table->boolean('passed');
            $table->json('report');
            $table->foreignId('ran_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            $table->index(['tenant_id', 'period', 'ran_at']);
        });

        // The monthly-close artifact is an immutable historical fact.
        DB::unprepared(
            "CREATE TRIGGER reconciliation_runs_no_update BEFORE UPDATE ON reconciliation_runs\n".
            "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'reconciliation_runs is append-only: UPDATE is forbidden';"
        );
        DB::unprepared(
            "CREATE TRIGGER reconciliation_runs_no_delete BEFORE DELETE ON reconciliation_runs\n".
            "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'reconciliation_runs is append-only: DELETE is forbidden';"
        );
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS reconciliation_runs_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS reconciliation_runs_no_delete');
        Schema::dropIfExists('reconciliation_runs');
    }
};
