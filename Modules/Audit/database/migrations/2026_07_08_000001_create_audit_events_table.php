<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Partitioned + trigger-guarded table: raw portable DDL (no Eloquent
        // schema builder, no FKs — partitioned tables cannot carry them).
        DB::unprepared($this->createTableSql());

        // Belt AND braces append-only enforcement at the DB level. These fire
        // regardless of the connecting user (dev uses root; production also runs
        // under a least-privilege user with UPDATE/DELETE revoked — see DEFERRED).
        DB::unprepared(
            "CREATE TRIGGER audit_events_no_update BEFORE UPDATE ON audit_events\n".
            "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_events is append-only: UPDATE is forbidden';"
        );
        DB::unprepared(
            "CREATE TRIGGER audit_events_no_delete BEFORE DELETE ON audit_events\n".
            "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_events is append-only: DELETE is forbidden';"
        );
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS audit_events_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS audit_events_no_delete');
        DB::unprepared('DROP TABLE IF EXISTS audit_events');
    }

    private function createTableSql(): string
    {
        return <<<SQL
CREATE TABLE audit_events (
    id CHAR(26) NOT NULL,
    tenant_id CHAR(26) NULL,
    actor_type VARCHAR(20) NOT NULL,
    actor_id CHAR(26) NULL,
    action VARCHAR(191) NOT NULL,
    resource_type VARCHAR(191) NULL,
    resource_id CHAR(26) NULL,
    patient_id CHAR(26) NULL,
    before_hash CHAR(64) NULL,
    after_hash CHAR(64) NULL,
    reason TEXT NULL,
    ip VARCHAR(45) NULL,
    ua VARCHAR(255) NULL,
    context JSON NULL,
    occurred_at DATETIME(6) NOT NULL,
    prev_hash CHAR(64) NULL,
    hash CHAR(64) NOT NULL,
    PRIMARY KEY (id, occurred_at),
    KEY idx_tenant_time (tenant_id, occurred_at),
    KEY idx_tenant_patient_time (tenant_id, patient_id, occurred_at),
    KEY idx_tenant_resource_time (tenant_id, resource_type, resource_id, occurred_at)
)
PARTITION BY RANGE COLUMNS(occurred_at) (
{$this->partitionDefinitions()}
);
SQL;
    }

    /**
     * Current month + the next three months, plus a MAXVALUE catch-all.
     * REORGANIZE-based maintenance (audit:ensure-partitions) extends this later.
     */
    private function partitionDefinitions(): string
    {
        $start = Carbon::now()->startOfMonth();
        $lines = [];

        for ($i = 0; $i < 4; $i++) {
            $month = $start->copy()->addMonths($i);
            $upper = $month->copy()->addMonth()->format('Y-m-d');
            $name = 'p_'.$month->format('Y_m');
            $lines[] = "    PARTITION {$name} VALUES LESS THAN ('{$upper} 00:00:00')";
        }

        $lines[] = '    PARTITION p_max VALUES LESS THAN (MAXVALUE)';

        return implode(",\n", $lines);
    }
};
