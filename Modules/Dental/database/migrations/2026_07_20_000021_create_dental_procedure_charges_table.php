<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * dental_procedure_charges (DENTAL.G3) — the LIGHT tooth link: when a tooth-scoped dental
 * procedure is charged (through the existing ChargeCaptureService), this row ties the
 * resulting billing `charge` to the odontogram tooth/surface it was done on. It stores no
 * money — the charge (Billing) owns all economics; this only records which tooth. The full
 * "perform a procedure" workflow is DENTAL.G4.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dental_procedure_charges', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('charge_id');
            $table->ulid('dental_procedure_id');
            $table->string('tooth', 2)->nullable();   // FDI id from the G1 odontogram
            $table->string('surface')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('charge_id')->references('id')->on('charges')->cascadeOnDelete();
            $table->foreign('dental_procedure_id')->references('id')->on('dental_procedures')->cascadeOnDelete();
            $table->unique('charge_id'); // one tooth link per charge
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dental_procedure_charges');
    }
};
