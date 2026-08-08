<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Staff invitations (SETTINGS.P6): a tenant-bound, single-use, expiring token that, on accept,
     * provisions a User in THIS tenant with the invited built-in role via the real RBAC path. The
     * plaintext token is never stored — only its sha256 hash. Status is append-only in spirit
     * (pending → accepted | revoked | expired); an accepted/revoked/expired token no longer resolves.
     */
    public function up(): void
    {
        Schema::create('staff_invites', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->string('email');
            $table->char('role_id', 26);            // a built-in system Role template (tenant-scoped)
            $table->char('token_hash', 64);         // sha256 of the single-use plaintext token
            $table->string('status')->default('pending'); // pending | accepted | revoked | expired
            $table->unsignedBigInteger('invited_by')->nullable();
            $table->dateTime('expires_at');
            $table->dateTime('accepted_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->foreign('invited_by')->references('id')->on('users')->nullOnDelete();

            $table->unique('token_hash');
            $table->index(['tenant_id', 'email', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_invites');
    }
};
