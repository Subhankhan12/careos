<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tenant-owned (BelongsToTenant): every row carries tenant_id.
        Schema::create('credentials', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('staff_profile_id');
            $table->string('type');
            $table->string('name');
            $table->string('issuing_authority')->nullable();
            $table->string('identifier')->nullable();
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->string('status')->default('valid');
            $table->string('document_path')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('staff_profile_id')->references('id')->on('staff_profiles')->cascadeOnDelete();

            $table->index(['tenant_id', 'expires_on']);
            $table->index(['tenant_id', 'staff_profile_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credentials');
    }
};
