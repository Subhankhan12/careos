<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('thread_reads', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('thread_id');
            $table->foreignId('staff_user_id')->constrained('users')->cascadeOnDelete();
            $table->ulid('last_read_message_id')->nullable();
            $table->timestamp('read_at');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('thread_id')->references('id')->on('threads')->cascadeOnDelete();

            $table->unique(['tenant_id', 'thread_id', 'staff_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('thread_reads');
    }
};
