<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

/**
 * The schedule is the whole point of the automation layer: a command nobody
 * registered is a command that never runs. These assertions are about the
 * REGISTRATION, not about Laravel's cron parser.
 */

/**
 * @return array<string, Event>
 */
function scheduledEvents(): array
{
    $events = [];

    foreach (app(Schedule::class)->events() as $event) {
        // "'php' 'artisan' credentials:refresh-status" -> credentials:refresh-status
        if (preg_match('/artisan[\'"]? (?<command>[a-z]+:[a-z-]+)/', $event->command ?? '', $matches) === 1) {
            $events[$matches['command']] = $event;
        }
    }

    return $events;
}

test('every deferred command is registered on the scheduler with its intended cadence', function () {
    $events = scheduledEvents();

    $expected = [
        'credentials:refresh-status' => '10 2 * * *',
        'nursing:materialize-visits' => '20 2 * * *',
        'clinical:evaluate-recalls' => '30 2 * * *',
        'billing:dunning-run' => '0 6 * * *',
        'billing:reconcile' => '30 6 * * *',
        'appointments:dispatch-reminders' => '*/15 * * * *',
    ];

    foreach ($expected as $command => $expression) {
        expect($events)->toHaveKey($command)
            ->and($events[$command]->expression)->toBe($expression);
    }
});

test('every scheduled sweep is guarded against overlapping itself', function () {
    foreach (scheduledEvents() as $command => $event) {
        // These sweeps walk every tenant; a slow run must never be re-entered
        // by the next tick.
        expect($event->withoutOverlapping)->toBeTrue("{$command} is not withoutOverlapping()")
            ->and($event->expiresAt)->toBeGreaterThan(0, "{$command} has no overlap-lock expiry")
            ->and($event->onOneServer)->toBeTrue("{$command} is not onOneServer()");
    }
});

test('the scheduler registers exactly the six intended commands and nothing else', function () {
    // Guards against a future gate quietly scheduling something unattended —
    // e.g. one of the Attempt* parallel-hammer test helpers.
    expect(array_keys(scheduledEvents()))->toEqualCanonicalizing([
        'credentials:refresh-status',
        'nursing:materialize-visits',
        'clinical:evaluate-recalls',
        'billing:dunning-run',
        'billing:reconcile',
        'appointments:dispatch-reminders',
    ]);
});
