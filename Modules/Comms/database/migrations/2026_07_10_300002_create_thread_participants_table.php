<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('thread_participants', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('thread_id');
            $table->string('participant_type');
            $table->foreignId('staff_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->ulid('patient_id')->nullable();
            $table->timestamp('added_at');
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('thread_id')->references('id')->on('threads')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->restrictOnDelete();

            $table->index(['tenant_id', 'thread_id']);
            $table->index(['tenant_id', 'staff_user_id']);
            $table->index(['tenant_id', 'patient_id']);
        });

        // Exactly one of staff_user_id / patient_id (XOR), portable on
        // MariaDB 10.4 and MySQL 8.
        DB::statement('ALTER TABLE thread_participants ADD CONSTRAINT thread_participants_exactly_one_party CHECK ((staff_user_id IS NULL) <> (patient_id IS NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('thread_participants');
    }
};
