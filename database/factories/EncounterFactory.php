<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Clinical\Models\Encounter;
use Modules\Patients\Models\Patient;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Models\Branch;

/**
 * Driven explicitly (`EncounterFactory::new()->create()`): the Clinical models
 * live in `Modules\*` and do not use `HasFactory`.
 *
 * Use this for historical/bulk encounters. Encounters a demo should show being
 * *worked* go through EncounterService::open() instead, so the audit trail and
 * the appointment transition are real.
 *
 * @extends Factory<Encounter>
 */
class EncounterFactory extends Factory
{
    protected $model = Encounter::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => Encounter::TYPE_CONSULTATION,
            'status' => Encounter::STATUS_CLOSED,
            'reason_for_visit' => fake()->randomElement([
                'Routine check-up',
                'Follow-up on existing treatment plan',
                'Medication review',
                'Wound dressing change',
                'Blood pressure review',
            ]),
        ];
    }

    public function forPatient(Patient $patient): static
    {
        return $this->state(fn (array $attributes): array => ['patient_id' => $patient->id]);
    }

    public function withPractitioner(StaffProfile $practitioner): static
    {
        return $this->state(fn (array $attributes): array => ['practitioner_id' => $practitioner->id]);
    }

    public function atBranch(Branch $branch): static
    {
        return $this->state(fn (array $attributes): array => ['branch_id' => $branch->id]);
    }

    public function ofType(string $type): static
    {
        return $this->state(fn (array $attributes): array => ['type' => $type]);
    }

    /**
     * A closed encounter spanning a plausible working slot on the given day.
     */
    public function on(string $date, string $startTime = '09:00:00', int $minutes = 30): static
    {
        return $this->state(fn (array $attributes): array => [
            'started_at' => $date.' '.$startTime,
            'ended_at' => $date.' '.$startTime,
            'status' => Encounter::STATUS_CLOSED,
        ])->afterMaking(function (Encounter $encounter) use ($minutes): void {
            $encounter->ended_at = $encounter->started_at->copy()->addMinutes($minutes);
        });
    }

    public function open(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => Encounter::STATUS_OPEN,
            'ended_at' => null,
        ]);
    }
}
