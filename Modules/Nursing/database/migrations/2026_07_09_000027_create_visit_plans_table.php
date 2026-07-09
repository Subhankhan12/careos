<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visit_plans', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('service_agreement_id');
            $table->ulid('agreement_service_id');
            $table->text('rrule');
            $table->string('timezone');
            $table->time('window_start_time');
            $table->time('window_end_time');
            $table->unsignedSmallInteger('duration_minutes');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('service_agreement_id')->references('id')->on('service_agreements')->cascadeOnDelete();
            $table->foreign('agreement_service_id')->references('id')->on('agreement_services')->cascadeOnDelete();

            $table->index(['tenant_id', 'service_agreement_id']);
            $table->index(['tenant_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visit_plans');
    }
};
