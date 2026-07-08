<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_resources', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('appointment_id');
            $table->ulid('resource_id');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('appointment_id')->references('id')->on('appointments')->cascadeOnDelete();
            $table->foreign('resource_id')->references('id')->on('resources')->restrictOnDelete();

            $table->unique(['tenant_id', 'appointment_id', 'resource_id'], 'appointment_resources_unique');
            $table->index(['tenant_id', 'resource_id', 'appointment_id'], 'appointment_resources_lookup');
            $table->index(['tenant_id', 'appointment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_resources');
    }
};
