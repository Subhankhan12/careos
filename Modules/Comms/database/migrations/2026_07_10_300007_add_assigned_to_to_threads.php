<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('threads', function (Blueprint $table): void {
            $table->foreignId('assigned_to')->nullable()->after('created_by')->constrained('users')->restrictOnDelete();
            $table->index(['tenant_id', 'assigned_to'], 'threads_assignment_index');
        });
    }

    public function down(): void
    {
        Schema::table('threads', function (Blueprint $table): void {
            $table->dropIndex('threads_assignment_index');
            $table->dropConstrainedForeignId('assigned_to');
        });
    }
};
