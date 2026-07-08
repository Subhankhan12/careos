<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Platform-level table: NOT tenant-owned, no tenant_id, no self-scoping.
        Schema::create('tenants', function (Blueprint $table) {
            $table->ulid('id')->primary();        // char(26), portable MariaDB 10.4 / MySQL 8
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('region')->default('eu');       // 'eu' | 'us', immutable after create
            $table->string('status')->default('provisioning'); // provisioning | active | suspended
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
