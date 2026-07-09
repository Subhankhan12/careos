<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visit_observations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('visit_id');
            $table->ulid('patient_id');
            $table->ulid('nurse_resource_id');
            $table->string('client_action_uuid');
            $table->text('note_text');
            $table->boolean('flagged')->default(false);
            $table->string('flag_reason')->nullable();
            $table->dateTime('device_timestamp');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('visit_id')->references('id')->on('visits')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->restrictOnDelete();
            $table->foreign('nurse_resource_id')->references('id')->on('resources')->restrictOnDelete();

            $table->unique(['tenant_id', 'client_action_uuid']);
            $table->index(['tenant_id', 'visit_id']);
            $table->index(['tenant_id', 'patient_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visit_observations');
    }
};
