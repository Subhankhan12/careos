<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referrals', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('patient_id');
            $table->ulid('encounter_id')->nullable();
            $table->string('direction');
            $table->string('to_provider_name')->nullable();
            $table->string('from_provider_name')->nullable();
            $table->ulid('to_branch_id')->nullable();
            $table->string('specialty')->nullable();
            $table->text('reason');
            $table->string('status')->default('draft');
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('responded_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->restrictOnDelete();
            $table->foreign('encounter_id')->references('id')->on('encounters')->nullOnDelete();
            $table->foreign('to_branch_id')->references('id')->on('branches')->restrictOnDelete();

            $table->index(['tenant_id', 'patient_id', 'status']);
            $table->index(['tenant_id', 'direction', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referrals');
    }
};
