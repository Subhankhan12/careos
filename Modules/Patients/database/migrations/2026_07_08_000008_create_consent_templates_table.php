<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tenant-owned (BelongsToTenant): every row carries tenant_id.
        Schema::create('consent_templates', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->string('key');
            $table->string('title');
            $table->text('body');
            $table->unsignedInteger('version');
            $table->json('scope_keys');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            $table->unique(['tenant_id', 'key', 'version']);
            $table->index(['tenant_id', 'key', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consent_templates');
    }
};
