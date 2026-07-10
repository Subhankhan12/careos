<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('thread_id');
            $table->string('author_type');
            $table->foreignId('author_staff_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->ulid('author_patient_id')->nullable();
            $table->text('body');
            $table->boolean('ai_assisted')->default(false);
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('thread_id')->references('id')->on('threads')->cascadeOnDelete();
            $table->foreign('author_patient_id')->references('id')->on('patients')->restrictOnDelete();

            $table->index(['tenant_id', 'thread_id', 'sent_at']);
        });

        // Messages are append-only communications evidence: what was said to a
        // patient (or internally about care) must never be silently rewritten.
        // Corrections are NEW messages; the record of the original stands, the
        // same posture as audit_events and the financial ledgers.
        DB::unprepared(
            "CREATE TRIGGER messages_no_update BEFORE UPDATE ON messages\n".
            "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'messages is append-only: UPDATE is forbidden';"
        );
        DB::unprepared(
            "CREATE TRIGGER messages_no_delete BEFORE DELETE ON messages\n".
            "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'messages is append-only: DELETE is forbidden';"
        );
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS messages_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS messages_no_delete');
        Schema::dropIfExists('messages');
    }
};
