<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agreement_services', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('service_agreement_id');
            $table->ulid('service_id');
            $table->text('planned_frequency_text');
            $table->string('required_qualification')->nullable();
            $table->unsignedSmallInteger('duration_minutes');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('service_agreement_id')->references('id')->on('service_agreements')->cascadeOnDelete();
            $table->foreign('service_id')->references('id')->on('services')->restrictOnDelete();

            $table->index(['tenant_id', 'service_agreement_id']);
            $table->index(['tenant_id', 'service_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agreement_services');
    }
};
