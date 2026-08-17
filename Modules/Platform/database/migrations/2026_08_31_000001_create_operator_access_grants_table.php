<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // OPMODE.G1 — the grant that is the ONLY thing opening a tenant's data to a
        // platform operator (a super-admin, tenant_id null).
        //
        // DELIBERATELY *NOT* `BelongsToTenant`. This row is PLATFORM-owned and merely
        // REFERENCES a tenant: the operator has no tenant context until a grant is
        // resolved, so scoping the grant itself to a tenant context would be circular
        // (you would need the grant to read the grant). It is queried outside
        // TenantScope, always constrained explicitly by operator_id + tenant_id.
        //
        // NOT the BreakGlass self-grant model: `status` starts `pending` and only an
        // approval path (G2/G3) may activate a tier that requires one. Requesting is
        // never granting.
        Schema::create('operator_access_grants', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // WHO — the platform operator this grant belongs to.
            $table->unsignedBigInteger('operator_id');

            // WHERE — the TARGET tenant whose data this grant may open.
            $table->char('tenant_id', 26);

            // WHAT — read_only | configuration | full_support (see OperatorGrant::TIERS).
            $table->string('tier', 20);

            // HOW MUCH — the resource ids this grant may reach, per kind, e.g.
            // {"patients": ["01J…","01K…"]}. An empty/absent kind means NOTHING of
            // that kind is in scope (fail-closed — never "unrestricted").
            $table->json('scope')->nullable();

            // WHY — required, recorded verbatim (the BreakGlass reason discipline).
            $table->text('reason');

            // LIFECYCLE. G1 uses pending/active/expired/revoked; declined is written by
            // the owner-decision flow in G3.
            $table->string('status', 20)->default('pending');

            // P0P.G15: mutable moments are dateTime(), never timestamp() — MariaDB 10.4
            // gives the first non-nullable TIMESTAMP an implicit ON UPDATE CURRENT_TIMESTAMP.
            $table->dateTime('granted_at')->nullable();
            $table->dateTime('expires_at')->nullable();   // the SERVER-enforced session TTL
            $table->dateTime('revoked_at')->nullable();
            $table->unsignedBigInteger('revoked_by')->nullable();
            $table->timestamps();

            $table->foreign('operator_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('revoked_by')->references('id')->on('users')->nullOnDelete();

            // The hot path: "is there an active grant for this operator in this tenant?"
            $table->index(['operator_id', 'tenant_id', 'status']);
            $table->index(['tenant_id', 'status']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operator_access_grants');
    }
};
