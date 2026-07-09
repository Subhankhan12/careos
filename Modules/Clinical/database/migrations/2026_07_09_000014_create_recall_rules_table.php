<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recall_rules', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->string('name');
            $table->json('criteria');
            $table->unsignedInteger('interval_months');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            $table->index(['tenant_id', 'active']);
        });

        Schema::create('recalls', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('patient_id');
            $table->ulid('rule_id');
            $table->date('due_on');
            $table->string('status')->default('due');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->restrictOnDelete();
            $table->foreign('rule_id')->references('id')->on('recall_rules')->cascadeOnDelete();

            $table->unique(['tenant_id', 'patient_id', 'rule_id', 'due_on']);
            $table->index(['tenant_id', 'patient_id', 'status']);
            $table->index(['tenant_id', 'rule_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recalls');
        Schema::dropIfExists('recall_rules');
    }
};
