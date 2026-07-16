<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Automation layer (P0P.G2)
|--------------------------------------------------------------------------
|
| The unattended cadences. Every command below iterates ACTIVE tenants only and
| is safe to run repeatedly — each command's class docblock says why.
|
| PRODUCTION RUNNER: none of this fires unless `schedule:run` is invoked every
| minute, and the queued work (appointment reminders, notification deliveries)
| only drains while Horizon is running:
|
|   cron:        * * * * * cd /srv/careos && php artisan schedule:run >> /dev/null 2>&1
|   supervisor:  php artisan horizon
|
| Local Windows cannot keep Horizon alive — this PHP has no pcntl, so
| `php artisan horizon` exits right after startup. That is a known LOCAL-only
| limitation (CI and Linux prod install pcntl/posix). Locally, use
| `php artisan schedule:work` and, in place of Horizon,
| `php artisan queue:work redis --queue=reminders,notifications`.
|
| withoutOverlapping() everywhere: these sweeps walk every tenant and can outrun
| their own cadence on a large instance. Without the lock a slow reminder sweep
| would be re-entered every 15 minutes and the tenant loops would race. Each
| lock carries an expiry so a killed worker cannot wedge a job forever.
|
| onOneServer() everywhere: these are cluster-wide sweeps, not per-node work. If
| prod ever runs more than one scheduler node, only one may execute each event.
|
*/

// Credential expiry statuses are derived from expires_on; recomputing them
// before the working day means the vault is honest when staff arrive.
Schedule::command('credentials:refresh-status')
    ->dailyAt('02:10')
    ->withoutOverlapping(30)
    ->onOneServer();

// Rolling 8-week horizon of planned nursing visits. Idempotent via
// unique(tenant, visit_plan, scheduled_date) + upsert.
Schedule::command('nursing:materialize-visits')
    ->dailyAt('02:20')
    ->withoutOverlapping(30)
    ->onOneServer();

// Deterministic recall generation from documented problem codes.
Schedule::command('clinical:evaluate-recalls')
    ->dailyAt('02:30')
    ->withoutOverlapping(30)
    ->onOneServer();

// Staged dunning. A legal communication, so not consent-gated (D-F7); a level
// fires at most once per invoice.
Schedule::command('billing:dunning-run')
    ->dailyAt('06:00')
    ->withoutOverlapping(30)
    ->onOneServer();

// THE LAUNCH-BLOCKER MONITOR: reconcile the current period for every active
// tenant, daily. Writes an append-only reconciliation_runs row either way; a
// failure also logs at error level and sets the billing.reconciliation.alarm
// tenant setting. See ReconciliationAlarm.
Schedule::command('billing:reconcile')
    ->dailyAt('06:30')
    ->withoutOverlapping(30)
    ->onOneServer();

// Reminders are ENQUEUED here, not sent: the job re-checks comms.email consent
// at send time and skips without it. Every 15 minutes keeps the enqueue lag well
// inside the smallest reminder offset.
Schedule::command('appointments:dispatch-reminders')
    ->everyFifteenMinutes()
    ->withoutOverlapping(10)
    ->onOneServer();

// Expire timed-out waitlist offers (P0P.G9) so a freed slot held for an
// unresponsive patient is released back to the next matching candidate. The TTL
// is short (scheduling.waitlist.offer_ttl_minutes, default 30), so sweep often.
Schedule::command('scheduling:expire-waitlist-offers')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->onOneServer();

// THE TAMPER ALARM: replay every active tenant's audit hash-chain and record an
// append-only integrity_checks row either way. A break means a row was altered
// or removed by something that went around both the model guards and the DB
// triggers — nobody would notice on their own, so this looks every day.
// Early, before the day's writes, and before the billing sweeps.
Schedule::command('audit:verify-chains')
    ->dailyAt('01:30')
    ->withoutOverlapping(30)
    ->onOneServer();
