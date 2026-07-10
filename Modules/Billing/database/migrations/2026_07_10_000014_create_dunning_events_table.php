<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dunning_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('invoice_id');
            $table->unsignedSmallInteger('level');
            $table->date('triggered_on');
            $table->string('document_path')->nullable();
            $table->string('status')->default('created');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('invoice_id')->references('id')->on('invoices')->restrictOnDelete();

            // A level fires at most once per invoice — the idempotency backstop.
            $table->unique(['tenant_id', 'invoice_id', 'level']);
            $table->index(['tenant_id', 'invoice_id']);
        });

        // Append-only: a dunning event is an immutable historical fact. Its status
        // is decided at insert time (created/sent); it is never edited or deleted.
        DB::unprepared(
            "CREATE TRIGGER dunning_events_no_update BEFORE UPDATE ON dunning_events\n".
            "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'dunning_events is append-only: UPDATE is forbidden';"
        );
        DB::unprepared(
            "CREATE TRIGGER dunning_events_no_delete BEFORE DELETE ON dunning_events\n".
            "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'dunning_events is append-only: DELETE is forbidden';"
        );
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS dunning_events_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS dunning_events_no_delete');
        Schema::dropIfExists('dunning_events');
    }
};
