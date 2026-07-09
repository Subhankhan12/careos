<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('planned_visits', function (Blueprint $table): void {
            $table->dateTime('assigned_at')->nullable()->after('assigned_resource_id');
            $table->foreignId('assigned_by')->nullable()->after('assigned_at')->constrained('users')->nullOnDelete();
            $table->decimal('location_latitude', 9, 6)->nullable()->after('cancellation_reason');
            $table->decimal('location_longitude', 9, 6)->nullable()->after('location_latitude');

            $table->index(['tenant_id', 'assigned_resource_id', 'window_start_at'], 'planned_visits_assignment_window_idx');
        });
    }

    public function down(): void
    {
        Schema::table('planned_visits', function (Blueprint $table): void {
            $table->dropIndex('planned_visits_assignment_window_idx');
            $table->dropForeign(['assigned_by']);
            $table->dropColumn([
                'assigned_at',
                'assigned_by',
                'location_latitude',
                'location_longitude',
            ]);
        });
    }
};
