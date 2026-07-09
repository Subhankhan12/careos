<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medications', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('patient_id');
            $table->string('name');
            $table->string('substance_key');
            $table->text('dose_text')->nullable();
            $table->string('route')->nullable();
            $table->string('frequency_text')->nullable();
            $table->date('started_on');
            $table->date('ended_on')->nullable();
            $table->string('status')->default('active');
            $table->ulid('recorded_by');
            $table->dateTime('recorded_at');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->restrictOnDelete();
            $table->foreign('recorded_by')->references('id')->on('staff_profiles')->restrictOnDelete();

            $table->index(['tenant_id', 'patient_id', 'status']);
            $table->index(['tenant_id', 'patient_id', 'substance_key', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medications');
    }
};
