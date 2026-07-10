<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->string('key');
            $table->string('channel');
            $table->string('locale', 12)->default('en');
            $table->string('subject')->nullable();
            $table->text('body');
            $table->string('category');
            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            $table->unique(['tenant_id', 'key', 'channel', 'locale', 'version'], 'notification_templates_identity_unique');
            $table->index(['tenant_id', 'key', 'channel', 'active'], 'notification_templates_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_templates');
    }
};
