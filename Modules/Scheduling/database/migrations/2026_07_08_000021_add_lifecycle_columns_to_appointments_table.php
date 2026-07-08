<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->ulid('rescheduled_from_id')->nullable()->after('id');
            $table->text('status_reason')->nullable()->after('status');
            $table->string('status_changed_by')->nullable()->after('booked_by');
            $table->dateTime('status_changed_at')->nullable()->after('status_changed_by');

            $table->foreign('rescheduled_from_id')->references('id')->on('appointments')->nullOnDelete();
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropForeign(['rescheduled_from_id']);
            $table->dropIndex(['tenant_id', 'status']);
            $table->dropColumn([
                'rescheduled_from_id',
                'status_reason',
                'status_changed_by',
                'status_changed_at',
            ]);
        });
    }
};
