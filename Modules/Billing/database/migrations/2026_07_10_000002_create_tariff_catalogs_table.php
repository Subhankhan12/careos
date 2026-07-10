<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tariff_catalogs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->string('key');
            $table->string('name');
            $table->unsignedInteger('version');
            $table->char('currency', 3)->default('EUR');
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->string('status')->default('draft');
            $table->json('rules')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            $table->unique(['tenant_id', 'key', 'version']);
            $table->index(['tenant_id', 'key', 'valid_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tariff_catalogs');
    }
};
