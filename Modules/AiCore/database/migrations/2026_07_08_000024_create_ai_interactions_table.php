<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_interactions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->string('feature');
            $table->string('agent');
            $table->string('provider');
            $table->string('model');
            $table->string('model_version');
            $table->char('prompt_hash', 64);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cost_minor')->default(0);
            $table->json('tool_calls')->nullable();
            $table->string('output_ref')->nullable();
            $table->ulid('approver_id')->nullable();
            $table->unsignedInteger('latency_ms')->default(0);
            $table->string('outcome');
            $table->string('label')->default('AI draft - requires human review');
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at', 6);
            $table->timestamps();

            $table->index(['tenant_id', 'occurred_at']);
            $table->index(['tenant_id', 'feature', 'occurred_at']);
            $table->index(['tenant_id', 'outcome', 'occurred_at']);
        });

        DB::unprepared(
            "CREATE TRIGGER ai_interactions_no_update BEFORE UPDATE ON ai_interactions\n".
            "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ai_interactions is append-only: UPDATE is forbidden';"
        );
        DB::unprepared(
            "CREATE TRIGGER ai_interactions_no_delete BEFORE DELETE ON ai_interactions\n".
            "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ai_interactions is append-only: DELETE is forbidden';"
        );
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS ai_interactions_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS ai_interactions_no_delete');
        Schema::dropIfExists('ai_interactions');
    }
};
