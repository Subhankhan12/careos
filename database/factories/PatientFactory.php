<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\MrnGenerator;

/**
 * Invented Swiss/EU demographics for demo and test data. Every name here is
 * fictional — no real person's data ever enters a factory.
 *
 * The models live in `Modules\*` and deliberately do not use `HasFactory`, so
 * this factory is driven explicitly: `PatientFactory::new()->create()` or, when
 * a service owns the write, `PatientFactory::new()->raw()` for the attributes.
 *
 * @extends Factory<Patient>
 */
class PatientFactory extends Factory
{
    protected $model = Patient::class;

    /** @var list<string> */
    private const FEMALE_FIRST_NAMES = [
        'Anna', 'Beatrice', 'Chantal', 'Elena', 'Franziska', 'Gabriela', 'Heidi',
        'Irene', 'Katharina', 'Ladina', 'Martina', 'Nadine', 'Regula', 'Silvia', 'Verena',
    ];

    /** @var list<string> */
    private const MALE_FIRST_NAMES = [
        'Andreas', 'Bruno', 'Christoph', 'Daniel', 'Fabian', 'Gregor', 'Hans',
        'Jonas', 'Kilian', 'Lukas', 'Marco', 'Niklaus', 'Reto', 'Stefan', 'Urs',
    ];

    /** @var list<string> */
    private const SURNAMES = [
        'Ammann', 'Baumgartner', 'Brunner', 'Egli', 'Frei', 'Gerber', 'Hofmann',
        'Iten', 'Keller', 'Lüthi', 'Meier', 'Nussbaumer', 'Odermatt', 'Pfister',
        'Roth', 'Steiner', 'Tanner', 'Vogel', 'Weber', 'Zimmermann',
    ];

    /**
     * `mrn` is omitted on purpose: PatientService::create() generates it per
     * tenant under a row lock. `configure()` fills it only for direct create().
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $sex = fake()->randomElement(['female', 'male']);

        return [
            'first_name' => fake()->randomElement(
                $sex === 'female' ? self::FEMALE_FIRST_NAMES : self::MALE_FIRST_NAMES,
            ),
            'last_name' => fake()->randomElement(self::SURNAMES),
            'date_of_birth' => fake()->dateTimeBetween('-92 years', '-6 years')->format('Y-m-d'),
            'sex' => $sex,
            'preferred_language' => fake()->randomElement(['de', 'de', 'de', 'fr', 'it', 'en']),
            'status' => Patient::STATUS_ACTIVE,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Patient $patient): void {
            if ($patient->mrn === null) {
                $patient->mrn = app(MrnGenerator::class)->generate();
            }
        });
    }

    /**
     * A plausible near-duplicate of an existing patient: same surname and date
     * of birth, a first name that differs only as a nickname/spelling would.
     * This is what the demo dedup review screen has to reason about.
     */
    public function nearDuplicateOf(Patient $other, string $firstName): static
    {
        return $this->state(fn (array $attributes): array => [
            'first_name' => $firstName,
            'last_name' => $other->last_name,
            'date_of_birth' => $other->date_of_birth->toDateString(),
            'sex' => $other->sex,
        ]);
    }

    public function bornOn(string $date): static
    {
        return $this->state(fn (array $attributes): array => ['date_of_birth' => $date]);
    }

    public function named(string $firstName, string $lastName): static
    {
        return $this->state(fn (array $attributes): array => [
            'first_name' => $firstName,
            'last_name' => $lastName,
        ]);
    }
}
