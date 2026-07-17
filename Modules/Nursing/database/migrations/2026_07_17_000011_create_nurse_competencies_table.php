<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A competency HELD by a nurse (Scheduling practitioner resource). Competencies
 * can lapse like credentials, so a grant carries an optional expiry — a competency
 * is only "held" if active AND not expired (mirrors the credential-vault logic).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nurse_competencies', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('resource_id'); // the nurse (practitioner resource)
            $table->ulid('competency_id');
            $table->date('granted_at');
            $table->date('expires_at')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('resource_id')->references('id')->on('resources')->cascadeOnDelete();
            $table->foreign('competency_id')->references('id')->on('competencies')->cascadeOnDelete();

            $table->unique(['tenant_id', 'resource_id', 'competency_id']);
            $table->index(['tenant_id', 'resource_id', 'active']);
            $table->index(['tenant_id', 'competency_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nurse_competencies');
    }
};
