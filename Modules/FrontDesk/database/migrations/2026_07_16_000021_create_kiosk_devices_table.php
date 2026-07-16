<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tenant-owned (BelongsToTenant): a kiosk device is provisioned to ONE
        // branch and authorizes ONLY the check-in flow — never a login, never any
        // read outside resolve+check-in. The plaintext token is shown once; only
        // its sha256 hash is stored.
        Schema::create('kiosk_devices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->char('branch_id', 26);
            $table->string('name');
            $table->string('token_hash', 64)->unique();
            $table->boolean('active')->default(true);
            $table->dateTime('last_used_at')->nullable();
            $table->char('created_by', 26)->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->index(['tenant_id', 'branch_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kiosk_devices');
    }
};
