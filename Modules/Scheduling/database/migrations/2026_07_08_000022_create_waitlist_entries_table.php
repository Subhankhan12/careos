<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waitlist_entries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('patient_id');
            $table->ulid('service_id');
            $table->ulid('branch_id')->nullable();
            $table->dateTime('desired_starts_at')->nullable();
            $table->dateTime('desired_ends_at')->nullable();
            $table->boolean('flexible')->default(true);
            $table->integer('priority')->default(0);
            $table->string('status')->default('waiting');
            $table->dateTime('offered_starts_at')->nullable();
            $table->dateTime('offered_ends_at')->nullable();
            $table->ulid('offered_branch_id')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
            $table->foreign('service_id')->references('id')->on('services')->restrictOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            $table->foreign('offered_branch_id')->references('id')->on('branches')->nullOnDelete();

            $table->index(['tenant_id', 'service_id', 'status']);
            $table->index(['tenant_id', 'branch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waitlist_entries');
    }
};
