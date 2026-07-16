<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tenant-owned (BelongsToTenant): every row carries tenant_id.
        Schema::create('import_rows', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('import_batch_id');
            $table->unsignedInteger('row_number');
            $table->json('raw'); // the source row (header => value)
            $table->string('status')->default('pending'); // pending/valid/invalid/duplicate/imported/skipped
            $table->json('errors')->nullable(); // per-field validation messages
            $table->ulid('matched_patient_id')->nullable(); // set when dedup flags an existing patient
            $table->json('match')->nullable(); // score + confidence + reasons for the match
            $table->ulid('created_entity_id')->nullable(); // patient id, set on commit
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('import_batch_id')->references('id')->on('import_batches')->cascadeOnDelete();
            $table->foreign('matched_patient_id')->references('id')->on('patients')->nullOnDelete();
            $table->index(['tenant_id', 'import_batch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_rows');
    }
};
