<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('allergies', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('patient_id');
            $table->string('substance');
            $table->string('substance_key');
            $table->text('reaction')->nullable();
            $table->string('severity')->default('unknown');
            $table->string('status')->default('active');
            $table->ulid('recorded_by');
            $table->dateTime('recorded_at');
            $table->dateTime('verified_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->restrictOnDelete();
            $table->foreign('recorded_by')->references('id')->on('staff_profiles')->restrictOnDelete();

            $table->index(['tenant_id', 'patient_id', 'substance_key', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('allergies');
    }
};
