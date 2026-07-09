<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nurse_constraints', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('resource_id');
            $table->string('qualification');
            $table->decimal('max_hours_per_week', 5, 2);
            $table->unsignedSmallInteger('max_travel_minutes_between_visits');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('resource_id')->references('id')->on('resources')->cascadeOnDelete();

            $table->unique(['tenant_id', 'resource_id']);
            $table->index(['tenant_id', 'qualification']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nurse_constraints');
    }
};
