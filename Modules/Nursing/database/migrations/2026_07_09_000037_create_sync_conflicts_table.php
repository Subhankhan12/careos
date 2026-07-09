<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_conflicts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('visit_id')->nullable();
            $table->ulid('nurse_resource_id');
            $table->string('action_type');
            $table->json('client_payload');
            $table->json('server_state');
            $table->string('reason');
            $table->string('status')->default('open');
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('visit_id')->references('id')->on('visits')->nullOnDelete();
            $table->foreign('nurse_resource_id')->references('id')->on('resources')->restrictOnDelete();
            $table->foreign('resolved_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'visit_id']);
            $table->index(['tenant_id', 'nurse_resource_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_conflicts');
    }
};
