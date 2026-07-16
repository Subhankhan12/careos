<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clinical dot-phrases / quick-text macros (P0P.G10). Reusable text a clinician
 * expands while writing SOAP notes — PERSONAL (private to the author) or SHARED
 * (tenant-wide, admin-managed). Pure internal text; NO clinical interpretation.
 * The body may contain only whitelisted NON-clinical placeholders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('text_snippets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->string('scope'); // personal / shared
            $table->ulid('owner_staff_id')->nullable(); // set for personal, null for shared
            $table->string('trigger'); // the bare token, e.g. 'normalexam' (the '.' is UI sugar)
            $table->string('title');
            $table->text('body'); // the expansion (whitelisted non-clinical placeholders only)
            $table->string('specialty')->nullable(); // organizes shared libraries
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('owner_staff_id')->references('id')->on('staff_profiles')->cascadeOnDelete();

            // A clinician cannot have two of the same personal trigger. (Shared
            // trigger uniqueness is service-enforced: MySQL treats NULL owner as
            // distinct, so this composite index does not bind for shared rows.)
            $table->unique(['tenant_id', 'scope', 'owner_staff_id', 'trigger'], 'text_snippets_trigger_unique');
            $table->index(['tenant_id', 'owner_staff_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('text_snippets');
    }
};
