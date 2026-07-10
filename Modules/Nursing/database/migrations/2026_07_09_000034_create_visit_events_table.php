<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visit_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('visit_id');
            $table->string('type', 32);
            $table->dateTime('occurred_at', 6);
            $table->dateTime('received_at', 6);
            $table->geometry('location', 'point')->nullable();
            $table->geometry('location_index', 'point');
            $table->decimal('accuracy_meters', 8, 2)->nullable();
            $table->string('location_source', 20);
            $table->text('manual_reason')->nullable();
            $table->decimal('distance_meters', 10, 2)->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('visit_id')->references('id')->on('visits')->cascadeOnDelete();
            $table->unique(['tenant_id', 'visit_id', 'type'], 'visit_events_type_once');
            $table->index(['tenant_id', 'visit_id'], 'visit_events_visit_idx');
            $table->index(['tenant_id', 'recorded_by'], 'visit_events_recorded_by_idx');
            $table->spatialIndex('location_index', 'visit_events_location_spatial');
        });

        DB::unprepared(<<<'SQL'
CREATE TRIGGER visit_events_no_update BEFORE UPDATE ON visit_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'visit_events are append-only: UPDATE is forbidden';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER visit_events_no_delete BEFORE DELETE ON visit_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'visit_events are append-only: DELETE is forbidden';
END
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS visit_events_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS visit_events_no_delete');
        Schema::dropIfExists('visit_events');
    }
};
