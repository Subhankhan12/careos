<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_deliveries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->string('template_key');
            $table->unsignedInteger('template_version');
            $table->string('channel');
            $table->string('category');
            $table->string('recipient_type');
            $table->string('recipient_id');
            $table->ulid('patient_id')->nullable();
            $table->string('rendered_subject')->nullable();
            $table->text('rendered_body');
            $table->string('status');
            $table->string('skipped_reason')->nullable();
            $table->char('dedupe_key', 64);
            $table->timestamp('sent_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->restrictOnDelete();

            $table->unique(['tenant_id', 'dedupe_key']);
            $table->index(['tenant_id', 'template_key', 'status']);
            $table->index(['tenant_id', 'patient_id']);
        });

        // Delivery records mirror the F.6 dunning_events posture: the row is
        // written ONCE, when the delivery attempt (or skip decision) happens,
        // with its final status. History is never rewritten.
        DB::unprepared(
            "CREATE TRIGGER notification_deliveries_no_update BEFORE UPDATE ON notification_deliveries\n".
            "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'notification_deliveries is append-only: UPDATE is forbidden';"
        );
        DB::unprepared(
            "CREATE TRIGGER notification_deliveries_no_delete BEFORE DELETE ON notification_deliveries\n".
            "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'notification_deliveries is append-only: DELETE is forbidden';"
        );
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS notification_deliveries_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS notification_deliveries_no_delete');
        Schema::dropIfExists('notification_deliveries');
    }
};
