<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telehealth_participants', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('session_id');
            $table->string('participant_type');
            $table->string('participant_id');
            // DATETIME, not TIMESTAMP: MariaDB 10.4 gives the first TIMESTAMP
            // column implicit ON UPDATE CURRENT_TIMESTAMP, which would mutate
            // joined_at on the leave update (and trip the append-only trigger).
            $table->dateTime('joined_at');
            $table->dateTime('left_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('session_id')->references('id')->on('telehealth_sessions')->cascadeOnDelete();

            $table->index(['tenant_id', 'session_id']);
        });

        // Join/leave proof rows are append-only. Chosen shape: a join row whose
        // left_at is filled ONCE. The trigger allows exactly one transition —
        // left_at NULL -> value with every other column unchanged — and
        // forbids everything else; DELETE is always forbidden.
        DB::unprepared(<<<'SQL'
CREATE TRIGGER telehealth_participants_leave_once BEFORE UPDATE ON telehealth_participants
FOR EACH ROW
BEGIN
    IF OLD.left_at IS NOT NULL
        OR NEW.left_at IS NULL
        OR NEW.tenant_id <> OLD.tenant_id
        OR NEW.session_id <> OLD.session_id
        OR NEW.participant_type <> OLD.participant_type
        OR NEW.participant_id <> OLD.participant_id
        OR NEW.joined_at <> OLD.joined_at
    THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'telehealth_participants is append-only: only left_at may be set, once';
    END IF;
END
SQL);
        DB::unprepared(
            "CREATE TRIGGER telehealth_participants_no_delete BEFORE DELETE ON telehealth_participants\n".
            "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'telehealth_participants is append-only: DELETE is forbidden';"
        );
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS telehealth_participants_leave_once');
        DB::unprepared('DROP TRIGGER IF EXISTS telehealth_participants_no_delete');
        Schema::dropIfExists('telehealth_participants');
    }
};
