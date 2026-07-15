<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Clinical\Models\Encounter;
use Modules\Clinical\Models\Vital;
use Modules\Patients\Models\Patient;
use Modules\People\Models\StaffProfile;

/**
 * Driven explicitly (`VitalFactory::new()->create()`): the Clinical models live
 * in `Modules\*` and do not use `HasFactory`.
 *
 * RAW DOCUMENTED VALUES ONLY. This factory produces measurements and nothing
 * else — no flag, no range, no score, no interpretation, no "abnormal" state.
 * Deciding what a reading means is a clinician's job, never CareOS's.
 *
 * @extends Factory<Vital>
 */
class VitalFactory extends Factory
{
    protected $model = Vital::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'systolic' => fake()->numberBetween(102, 158),
            'diastolic' => fake()->numberBetween(62, 95),
            'heart_rate' => fake()->numberBetween(52, 96),
            'temperature_c' => fake()->randomFloat(1, 35.8, 38.4),
            'spo2' => fake()->numberBetween(92, 100),
            'weight_g' => fake()->numberBetween(48_000, 104_000),
            'height_mm' => fake()->numberBetween(1_520, 1_930),
        ];
    }

    public function forPatient(Patient $patient): static
    {
        return $this->state(fn (array $attributes): array => ['patient_id' => $patient->id]);
    }

    public function recordedBy(StaffProfile $recorder): static
    {
        return $this->state(fn (array $attributes): array => ['recorded_by' => $recorder->id]);
    }

    public function duringEncounter(Encounter $encounter): static
    {
        return $this->state(fn (array $attributes): array => [
            'encounter_id' => $encounter->id,
            'patient_id' => $encounter->patient_id,
            'recorded_at' => $encounter->started_at,
        ]);
    }

    public function recordedAt(string $moment): static
    {
        return $this->state(fn (array $attributes): array => ['recorded_at' => $moment]);
    }
}
