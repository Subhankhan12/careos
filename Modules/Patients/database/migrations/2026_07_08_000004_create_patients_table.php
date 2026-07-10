<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tenant-owned (BelongsToTenant): every row carries tenant_id.
        Schema::create('patients', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->string('mrn');
            $table->string('first_name');
            $table->string('last_name');
            $table->date('date_of_birth');
            $table->string('sex');
            $table->string('gender')->nullable();
            $table->string('preferred_language')->nullable();
            $table->timestamp('deceased_at')->nullable();
            $table->string('status')->default('active');
            $table->ulid('merged_into_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('merged_into_id')->references('id')->on('patients')->nullOnDelete();

            $table->unique(['tenant_id', 'mrn']);
            $table->index(['tenant_id', 'last_name', 'date_of_birth']);
        });

        $this->addPortableFullTextIndex();
    }

    public function down(): void
    {
        Schema::dropIfExists('patients');
    }

    private function addPortableFullTextIndex(): void
    {
        try {
            DB::statement(
                'ALTER TABLE patients ADD FULLTEXT INDEX patients_name_fulltext (first_name, last_name) WITH PARSER ngram'
            );
        } catch (QueryException $exception) {
            $message = strtolower($exception->getMessage());

            if (
                ! str_contains($message, 'ngram')
                && ! str_contains($message, 'parser')
                && ! str_contains($message, 'plugin')
            ) {
                throw $exception;
            }

            DB::statement(
                'ALTER TABLE patients ADD FULLTEXT INDEX patients_name_fulltext (first_name, last_name)'
            );
        }
    }
};
