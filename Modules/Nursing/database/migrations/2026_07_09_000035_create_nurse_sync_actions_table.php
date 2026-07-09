<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nurse_sync_actions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->string('client_action_uuid');
            $table->ulid('visit_id')->nullable();
            $table->ulid('nurse_resource_id');
            $table->string('action_type');
            $table->unsignedBigInteger('device_sequence');
            $table->dateTime('device_timestamp');
            $table->string('status');
            $table->string('result_code');
            $table->json('client_payload');
            $table->json('result_payload')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('visit_id')->references('id')->on('visits')->nullOnDelete();
            $table->foreign('nurse_resource_id')->references('id')->on('resources')->restrictOnDelete();

            $table->unique(['tenant_id', 'client_action_uuid']);
            $table->index(['tenant_id', 'visit_id']);
            $table->index(['tenant_id', 'nurse_resource_id', 'device_sequence'], 'nurse_sync_resource_sequence_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nurse_sync_actions');
    }
};
