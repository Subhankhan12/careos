<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('threads', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->string('subject');
            $table->string('type');
            $table->ulid('patient_id')->nullable();
            $table->ulid('encounter_id')->nullable();
            $table->string('status')->default('open');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->restrictOnDelete();
            $table->foreign('encounter_id')->references('id')->on('encounters')->restrictOnDelete();

            $table->index(['tenant_id', 'patient_id', 'last_message_at']);
            $table->index(['tenant_id', 'type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('threads');
    }
};
