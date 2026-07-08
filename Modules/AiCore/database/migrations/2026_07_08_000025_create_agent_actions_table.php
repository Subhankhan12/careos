<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_actions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('interaction_id')->nullable();
            $table->string('feature');
            $table->string('agent');
            $table->string('tool_key');
            $table->string('autonomy_level');
            $table->string('status');
            $table->ulid('proposed_by')->nullable();
            $table->ulid('reviewed_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('why');
            $table->json('input_payload');
            $table->json('proposed_output')->nullable();
            $table->json('diff')->nullable();
            $table->json('edited_payload')->nullable();
            $table->json('result')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'tool_key', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_actions');
    }
};
