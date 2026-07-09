<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('note_templates', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->string('name');
            $table->string('specialty')->nullable();
            $table->text('default_subjective')->nullable();
            $table->text('default_objective')->nullable();
            $table->text('default_assessment')->nullable();
            $table->text('default_plan')->nullable();
            $table->json('required_sections');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('note_templates');
    }
};
