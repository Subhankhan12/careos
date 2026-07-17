<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P0P.G15 engine-parity fix (same class as the D-E4/Comms DATETIME rule):
 * thread_reads.read_at was the first non-nullable TIMESTAMP on an UPDATE-able
 * table, so MariaDB attached an implicit ON UPDATE CURRENT_TIMESTAMP. Currently
 * masked (every marker update sets read_at explicitly) but a divergence trap —
 * a future update touching only last_read_message_id would silently rewrite
 * read_at on MariaDB and not on MySQL 8. Mutable moment columns are DATETIME.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('thread_reads', function (Blueprint $table): void {
            $table->dateTime('read_at')->change();
        });
    }

    public function down(): void
    {
        Schema::table('thread_reads', function (Blueprint $table): void {
            $table->timestamp('read_at')->change();
        });
    }
};
