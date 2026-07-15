<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\User;

/**
 * Driven explicitly (`StaffProfileFactory::new()->create()`): the People models
 * live in `Modules\*` and do not use `HasFactory`.
 *
 * @extends Factory<StaffProfile>
 */
class StaffProfileFactory extends Factory
{
    protected $model = StaffProfile::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $firstName = fake()->firstName();
        $lastName = fake()->lastName();

        return [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'display_name' => $firstName.' '.$lastName,
            'profession' => 'doctor',
            'status' => StaffProfile::STATUS_ACTIVE,
        ];
    }

    public function named(string $firstName, string $lastName, ?string $displayName = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'display_name' => $displayName ?? $firstName.' '.$lastName,
        ]);
    }

    public function profession(string $profession): static
    {
        return $this->state(fn (array $attributes): array => ['profession' => $profession]);
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes): array => ['user_id' => $user->id]);
    }

    public function atBranch(Branch $branch): static
    {
        return $this->state(fn (array $attributes): array => ['primary_branch_id' => $branch->id]);
    }
}
