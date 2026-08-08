<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tenant-scoped per-event EMAIL notification preferences (SETTINGS.P5). A row exists only for
     * an event whose email delivery an admin has changed; absence means the default (ON). The
     * NotificationService consults this for non-legal email events. There is deliberately NO SMS
     * column: no SMS provider is wired, so an SMS preference would gate nothing.
     */
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->string('event_key');
            $table->boolean('email_enabled')->default(true);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'event_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
