<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structured clinical orders (labs/imaging). The system records what is ordered
 * and tracks a status lifecycle — it NEVER interprets a result (P0P.G11).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('patient_id');
            $table->ulid('encounter_id')->nullable();
            $table->ulid('orderable_item_id');
            $table->foreignId('ordered_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('ordered_at');
            $table->string('priority')->default('routine'); // routine / urgent
            $table->text('clinical_note')->nullable(); // documented reason, free text — NOT generated
            $table->string('status')->default('ordered'); // ordered/collected/in_progress/resulted/reviewed/cancelled
            $table->text('cancelled_reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
            $table->foreign('encounter_id')->references('id')->on('encounters')->nullOnDelete();
            $table->foreign('orderable_item_id')->references('id')->on('orderable_items')->restrictOnDelete();

            $table->index(['tenant_id', 'patient_id', 'status']);
            $table->index(['tenant_id', 'status', 'ordered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
