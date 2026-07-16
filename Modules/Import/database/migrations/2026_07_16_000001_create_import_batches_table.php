<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tenant-owned (BelongsToTenant): every row carries tenant_id.
        Schema::create('import_batches', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->string('type')->default('patients'); // extensible import type
            $table->string('original_filename');
            $table->string('storage_path'); // private disk, tenant-prefixed, no public URL
            $table->string('status')->default('uploaded'); // uploaded/mapped/validated/committed/failed
            $table->unsignedInteger('row_count')->default(0);
            $table->string('date_format')->nullable(); // explicit date format the user selected
            $table->string('duplicate_policy')->nullable(); // skip/import_as_new/merge (chosen at commit)
            $table->json('mapping')->nullable(); // column header => CareOS field key
            $table->json('summary')->nullable(); // the dry-run result
            $table->char('created_by', 26)->nullable();
            $table->timestamp('committed_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batches');
    }
};
