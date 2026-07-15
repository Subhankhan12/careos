<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Modules\People\Models\Credential;
use Modules\People\Models\StaffProfile;
use Modules\People\Services\CredentialService;

/**
 * Driven explicitly (`CredentialFactory::new()->create()`): the People models
 * live in `Modules\*` and do not use `HasFactory`.
 *
 * Status is never hand-written: it is derived from `expires_on` through the
 * real {@see CredentialService}, so the expiry states a factory produces are
 * the same ones `credentials:refresh-status` would compute.
 *
 * @extends Factory<Credential>
 */
class CredentialFactory extends Factory
{
    protected $model = Credential::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => 'licence',
            'name' => 'Professional practice licence',
            'issuing_authority' => 'Gesundheitsdirektion Kanton Zürich',
            'identifier' => strtoupper(fake()->bothify('ZH-####-??')),
            'issued_on' => Carbon::today()->subYears(3)->toDateString(),
            'expires_on' => Carbon::today()->addYears(2)->toDateString(),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Credential $credential): void {
            $credential->status = app(CredentialService::class)->statusFor($credential->expires_on);
        });
    }

    public function forStaff(StaffProfile $staff): static
    {
        return $this->state(fn (array $attributes): array => ['staff_profile_id' => $staff->id]);
    }

    public function ofType(string $type, string $name): static
    {
        return $this->state(fn (array $attributes): array => ['type' => $type, 'name' => $name]);
    }

    /**
     * Inside the tenant's expiry-alert window, so the credential vault has
     * something to warn about.
     */
    public function expiringInDays(int $days): static
    {
        return $this->state(fn (array $attributes): array => [
            'expires_on' => Carbon::today()->addDays($days)->toDateString(),
        ]);
    }

    public function expiredDaysAgo(int $days): static
    {
        return $this->state(fn (array $attributes): array => [
            'expires_on' => Carbon::today()->subDays($days)->toDateString(),
        ]);
    }

    /**
     * Manual revocation is preserved over the derived expiry status, so it is
     * forced after the `configure()` hook has run.
     */
    public function revoked(): static
    {
        return $this->afterMaking(fn (Credential $credential) => $credential->status = Credential::STATUS_REVOKED);
    }
}
