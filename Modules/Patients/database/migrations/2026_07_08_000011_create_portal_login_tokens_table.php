<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tenant-owned (BelongsToTenant): every row carries tenant_id.
        Schema::create('portal_login_tokens', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('portal_account_id');
            $table->string('purpose');
            $table->char('token_hash', 64);
            $table->string('otp_hash');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('portal_account_id')->references('id')->on('portal_accounts')->cascadeOnDelete();

            $table->unique('token_hash');
            $table->index(['tenant_id', 'portal_account_id', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_login_tokens');
    }
};
