<?php

namespace Modules\Scheduling\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Modules\Scheduling\Jobs\SendAppointmentReminderJob;
use Modules\Scheduling\Models\Appointment;
use Modules\Scheduling\Models\AppointmentReminder;

class ReminderDispatcher
{
    public function __construct(
        private readonly ReminderPolicy $policy,
        private readonly ReminderChannelManager $channels,
    ) {}

    public function dispatchDue(CarbonInterface|string|null $now = null): int
    {
        $now = $now !== null ? CarbonImmutable::parse($now) : CarbonImmutable::now();
        $offsets = $this->policy->offsetMinutes();
        $channels = $this->policy->channels();
        $maxOffset = max($offsets);
        $count = 0;

        $appointments = Appointment::query()
            ->whereNotNull('patient_id')
            ->whereIn('status', Appointment::blockingStatuses())
            ->where('starts_at', '>', $now)
            ->where('starts_at', '<=', $now->addMinutes($maxOffset))
            ->get();

        foreach ($appointments as $appointment) {
            foreach ($offsets as $offset) {
                $scheduledFor = CarbonImmutable::parse($appointment->starts_at)->subMinutes($offset);

                if ($scheduledFor->greaterThan($now)) {
                    continue;
                }

                foreach ($channels as $channel) {
                    if (! $this->channels->has($channel)) {
                        continue;
                    }

                    $reminder = DB::transaction(function () use ($appointment, $offset, $channel, $scheduledFor): ?AppointmentReminder {
                        $existing = AppointmentReminder::query()
                            ->where('appointment_id', $appointment->id)
                            ->where('type', $this->policy->typeForOffset($offset))
                            ->where('channel', $channel)
                            ->lockForUpdate()
                            ->first();

                        if ($existing !== null) {
                            return null;
                        }

                        return AppointmentReminder::query()->create([
                            'appointment_id' => $appointment->id,
                            'type' => $this->policy->typeForOffset($offset),
                            'channel' => $channel,
                            'status' => AppointmentReminder::STATUS_PENDING,
                            'scheduled_for' => $scheduledFor,
                        ]);
                    });

                    if ($reminder === null) {
                        continue;
                    }

                    /*
                     * THE QUEUE IS THE ONE HORIZON CONSUMES (DEPLOY-FIX.1b).
                     *
                     * This used to say `->onQueue('reminders')` — the ONLY `onQueue()` in the codebase —
                     * while `config/horizon.php`'s sole supervisor consumes `['default']` in every
                     * environment. Measured with Redis up: redis `reminders` = 1, redis `default` = 0,
                     * nothing consuming. **No appointment reminder has ever been delivered by a worker.**
                     *
                     * The separate queue was INCIDENTAL, not designed: P0C.G5 (`8208484`) added this job,
                     * this dispatcher, the channel and the notification and never touched the Horizon
                     * config, and no comment or commit message anywhere argues for isolating it. The job
                     * is the same shape as `SendNotificationJob`, which has always run on `default`.
                     * Adding 'reminders' to the supervisor instead would have preserved an isolation
                     * nobody chose, and with `maxProcesses: 1` in the defaults it would have introduced a
                     * starvation question that `balance: 'auto'` only partly answers.
                     *
                     * `onConnection('redis')` STAYS, deliberately. It pins the reminder to the connection
                     * Horizon watches, so reminders keep working even on a host that left
                     * `QUEUE_CONNECTION=database` — the single most common deploy misconfiguration, and one
                     * the deploy checklist calls out as a silent failure.
                     */
                    SendAppointmentReminderJob::dispatch($reminder->tenant_id, $reminder->id)
                        ->onConnection('redis');
                    $count++;
                }
            }
        }

        return $count;
    }
}
