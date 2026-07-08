<?php

namespace Modules\Audit\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Idempotently ensure monthly partitions exist on audit_events up to N months
 * ahead, inserting them before the MAXVALUE catch-all. Portable across
 * MariaDB 10.4 and MySQL 8 (REORGANIZE PARTITION). Scheduling is deferred.
 */
class EnsureAuditPartitions extends Command
{
    protected $signature = 'audit:ensure-partitions {--months=12 : How many months ahead to guarantee}';

    protected $description = 'Ensure upcoming monthly partitions exist on audit_events (idempotent).';

    public function handle(): int
    {
        $months = max(0, (int) $this->option('months'));
        $start = Carbon::now()->startOfMonth();
        $added = 0;

        for ($i = 0; $i <= $months; $i++) {
            $month = $start->copy()->addMonths($i);
            $name = 'p_'.$month->format('Y_m');

            if ($this->partitionExists($name)) {
                continue;
            }

            $upper = $month->copy()->addMonth()->format('Y-m-d');

            // Split the MAXVALUE partition to insert the new month before it.
            DB::statement(
                'ALTER TABLE audit_events REORGANIZE PARTITION p_max INTO ('
                ."PARTITION {$name} VALUES LESS THAN ('{$upper} 00:00:00'), "
                .'PARTITION p_max VALUES LESS THAN (MAXVALUE))'
            );

            $added++;
        }

        $this->info("audit:ensure-partitions: {$added} partition(s) added.");

        return self::SUCCESS;
    }

    private function partitionExists(string $name): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.PARTITIONS '
            .'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND PARTITION_NAME = ?',
            ['audit_events', $name],
        );

        return $row !== null && (int) $row->c > 0;
    }
}
