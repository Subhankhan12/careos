<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('patient_id');
            $table->ulid('encounter_id')->nullable();
            $table->string('category');
            $table->string('title');
            $table->string('original_filename');
            $table->string('storage_path');
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('uploaded_at');
            $table->boolean('shared_with_patient')->default(false);
            $table->dateTime('shared_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->restrictOnDelete();
            $table->foreign('encounter_id')->references('id')->on('encounters')->nullOnDelete();

            $table->index(['tenant_id', 'patient_id', 'uploaded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
