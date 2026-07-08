<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tenant-owned (BelongsToTenant): every row carries tenant_id.
        Schema::create('resource_availability', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('resource_id');
            $table->unsignedTinyInteger('weekday')->nullable();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->date('date')->nullable();
            $table->boolean('is_available')->default(true);
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('resource_id')->references('id')->on('resources')->cascadeOnDelete();

            $table->index(['tenant_id', 'resource_id', 'weekday']);
            $table->index(['tenant_id', 'resource_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_availability');
    }
};
