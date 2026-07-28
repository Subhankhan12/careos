<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SURGERY.G2 — the surgical TEAM on a case: who is on the case (surgeon / anesthetist / scrub-OR nurse — the
 * G1 OR roles). A person appears once per case (`unique(tenant, case, staff)`); `team_role` is a plain
 * operational label. Tenant-scoped. The primary surgeon is already on the case (`surgical_cases.primary_surgeon_id`);
 * this records the rest of the team. NO judgment stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surgical_case_team_members', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('surgical_case_id');
            $table->ulid('staff_profile_id');
            $table->string('team_role'); // surgeon / anesthetist / scrub_nurse / … (a plain operational label)
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('surgical_case_id')->references('id')->on('surgical_cases')->cascadeOnDelete();
            $table->foreign('staff_profile_id')->references('id')->on('staff_profiles')->cascadeOnDelete();

            // One entry per person per case; explicit short name (the auto-name exceeds MySQL's 64-char limit).
            $table->unique(['tenant_id', 'surgical_case_id', 'staff_profile_id'], 'surg_case_team_member_unique');
            $table->index(['tenant_id', 'surgical_case_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surgical_case_team_members');
    }
};
