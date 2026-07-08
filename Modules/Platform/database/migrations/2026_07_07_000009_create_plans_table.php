<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // PLATFORM-level catalog: subscription plans, shared across tenants.
        Schema::create('plans', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('key')->unique();          // e.g. 'eu_pro'
            $table->string('name');
            $table->integer('price_minor')->default(0); // integer minor units (e.g. cents) — never floats
            $table->json('limits')->nullable();       // e.g. {"max_branches": 10, "max_staff": 100}
            $table->json('features')->nullable();     // default feature set for the plan
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
