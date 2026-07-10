<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charge_violations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('charge_id');
            $table->string('rule');
            $table->string('reason_code');
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('charge_id')->references('id')->on('charges')->cascadeOnDelete();

            $table->unique(['tenant_id', 'charge_id', 'rule', 'reason_code']);
            $table->index(['tenant_id', 'charge_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('charge_violations');
    }
};
