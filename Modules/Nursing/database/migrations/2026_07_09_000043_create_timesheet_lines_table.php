<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timesheet_lines', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('resource_id');
            $table->ulid('visit_id');
            $table->date('date');
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();
            $table->unsignedInteger('minutes')->nullable();
            $table->unsignedInteger('travel_minutes')->nullable();
            $table->json('discrepancy_flags')->nullable();
            $table->string('status')->default('draft');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('resource_id')->references('id')->on('resources')->restrictOnDelete();
            $table->foreign('visit_id')->references('id')->on('visits')->cascadeOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(['tenant_id', 'visit_id']);
            $table->index(['tenant_id', 'resource_id', 'date']);
            $table->index(['tenant_id', 'status']);
        });

        DB::unprepared(<<<'SQL'
CREATE TRIGGER timesheet_lines_approved_no_update BEFORE UPDATE ON timesheet_lines
FOR EACH ROW
BEGIN
    IF OLD.status = 'approved' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'approved timesheet_lines are immutable: UPDATE is forbidden';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER timesheet_lines_approved_no_delete BEFORE DELETE ON timesheet_lines
FOR EACH ROW
BEGIN
    IF OLD.status = 'approved' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'approved timesheet_lines are immutable: DELETE is forbidden';
    END IF;
END
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS timesheet_lines_approved_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS timesheet_lines_approved_no_delete');
        Schema::dropIfExists('timesheet_lines');
    }
};
