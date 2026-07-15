<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrity_checks', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->string('kind', 40);
            $table->timestamp('checked_at');
            $table->boolean('ok');
            $table->json('detail')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            $table->index(['tenant_id', 'kind', 'checked_at']);
            $table->index(['tenant_id', 'ok']);
        });

        // The result of an integrity check is itself evidence. A check that
        // could be rewritten after the fact would prove nothing — least of all
        // to whoever had a reason to rewrite it.
        DB::unprepared(
            "CREATE TRIGGER integrity_checks_no_update BEFORE UPDATE ON integrity_checks\n".
            "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'integrity_checks is append-only: UPDATE is forbidden';"
        );
        DB::unprepared(
            "CREATE TRIGGER integrity_checks_no_delete BEFORE DELETE ON integrity_checks\n".
            "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'integrity_checks is append-only: DELETE is forbidden';"
        );
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS integrity_checks_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS integrity_checks_no_delete');
        Schema::dropIfExists('integrity_checks');
    }
};
