<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tenant-owned (BelongsToTenant): time-boxed emergency access grants.
        Schema::create('break_glass_grants', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->unsignedBigInteger('user_id');
            $table->string('scope');              // e.g. 'patient:<id>' or a scope key
            $table->text('reason');               // required — captured for the audit trail
            $table->dateTime('granted_at');
            $table->dateTime('expires_at');       // auto-expiry checked at access time
            $table->boolean('activated')->default(true);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            $table->index(['tenant_id', 'user_id', 'scope']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('break_glass_grants');
    }
};
