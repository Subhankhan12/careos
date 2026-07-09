<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('visit_id')->nullable();
            $table->ulid('patient_id')->nullable();
            $table->ulid('reported_by_resource_id');
            $table->dateTime('occurred_at');
            $table->string('category');
            $table->text('description');
            $table->string('severity');
            $table->string('status')->default('open');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('visit_id')->references('id')->on('visits')->nullOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->nullOnDelete();
            $table->foreign('reported_by_resource_id')->references('id')->on('resources')->restrictOnDelete();

            $table->index(['tenant_id', 'patient_id', 'occurred_at']);
            $table->index(['tenant_id', 'visit_id']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incidents');
    }
};
