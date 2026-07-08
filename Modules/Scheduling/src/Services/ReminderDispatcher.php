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

                    SendAppointmentReminderJob::dispatch($reminder->tenant_id, $reminder->id)
                        ->onConnection('redis')
                        ->onQueue('reminders');
                    $count++;
                }
            }
        }

        return $count;
    }
}
