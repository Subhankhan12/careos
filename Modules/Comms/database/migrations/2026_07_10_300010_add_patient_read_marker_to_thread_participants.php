<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The patient-side analog of thread_reads (G.3): a read marker on the
        // patient's own participant row. Unread counts stay DERIVED.
        Schema::table('thread_participants', function (Blueprint $table): void {
            $table->ulid('last_read_message_id')->nullable()->after('removed_at');
        });
    }

    public function down(): void
    {
        Schema::table('thread_participants', function (Blueprint $table): void {
            $table->dropColumn('last_read_message_id');
        });
    }
};
