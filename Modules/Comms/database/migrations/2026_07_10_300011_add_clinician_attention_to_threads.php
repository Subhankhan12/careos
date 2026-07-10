<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Staff-facing flag set when the Inbox agent refuses a clinical
        // question (D-G5 electric fence): the thread needs a clinician, and no
        // draft exists. DATETIME per the MariaDB implicit-ON UPDATE wart.
        Schema::table('threads', function (Blueprint $table): void {
            $table->dateTime('clinician_attention_at')->nullable()->after('last_message_at');
            $table->string('clinician_attention_reason')->nullable()->after('clinician_attention_at');
        });
    }

    public function down(): void
    {
        Schema::table('threads', function (Blueprint $table): void {
            $table->dropColumn(['clinician_attention_at', 'clinician_attention_reason']);
        });
    }
};
