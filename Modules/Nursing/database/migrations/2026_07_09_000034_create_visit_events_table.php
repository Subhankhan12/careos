<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE visit_events (
    id CHAR(26) NOT NULL,
    tenant_id CHAR(26) NOT NULL,
    visit_id CHAR(26) NOT NULL,
    type VARCHAR(32) NOT NULL,
    occurred_at DATETIME(6) NOT NULL,
    received_at DATETIME(6) NOT NULL,
    location POINT /*!80003 SRID 4326 */ NULL,
    location_index POINT /*!80003 SRID 4326 */ NOT NULL,
    accuracy_meters DECIMAL(8, 2) NULL,
    location_source VARCHAR(20) NOT NULL,
    manual_reason TEXT NULL,
    distance_meters DECIMAL(10, 2) NULL,
    recorded_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    PRIMARY KEY (id),
    CONSTRAINT visit_events_tenant_id_foreign FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT visit_events_visit_id_foreign FOREIGN KEY (visit_id) REFERENCES visits(id) ON DELETE CASCADE,
    CONSTRAINT visit_events_recorded_by_foreign FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE RESTRICT,
    UNIQUE KEY visit_events_type_once (tenant_id, visit_id, type),
    KEY visit_events_visit_idx (tenant_id, visit_id),
    KEY visit_events_recorded_by_idx (tenant_id, recorded_by),
    SPATIAL INDEX visit_events_location_spatial (location_index)
)
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER visit_events_no_update BEFORE UPDATE ON visit_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'visit_events are append-only: UPDATE is forbidden';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER visit_events_no_delete BEFORE DELETE ON visit_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'visit_events are append-only: DELETE is forbidden';
END
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS visit_events_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS visit_events_no_delete');
        DB::unprepared('DROP TABLE IF EXISTS visit_events');
    }
};
