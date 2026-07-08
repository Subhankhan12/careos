<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tenant-owned (BelongsToTenant): every row carries tenant_id.
        Schema::create('resources', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->string('type');
            $table->string('name');
            $table->ulid('staff_profile_id')->nullable();
            $table->ulid('branch_id');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('staff_profile_id')->references('id')->on('staff_profiles')->nullOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();

            $table->index(['tenant_id', 'type']);
            $table->index(['tenant_id', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resources');
    }
};
