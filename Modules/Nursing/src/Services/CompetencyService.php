<?php

namespace Modules\Nursing\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Modules\Nursing\Events\CompetencyChanged;
use Modules\Nursing\Events\NurseCompetencyChanged;
use Modules\Nursing\Models\Competency;
use Modules\Nursing\Models\NurseCompetency;
use Modules\Platform\Models\User;
use Modules\Scheduling\Models\Resource;

/**
 * Manages a tenant's OWN competency list and the grants that link them to nurses.
 * There is NO bundled licensed set — a tenant authors its own competencies and sets
 * each one's enforcement (HARD blocks assignment, SOFT warns). The system never
 * decides which are safety-critical; it enforces what the agency configured.
 */
class CompetencyService
{
    /**
     * A small generic starter template, offered as EDITABLE — not a licensed set.
     * The default enforcement is a starting point the agency can change per row.
     *
     * @var list<array{code: string, name: string, enforcement: string}>
     */
    private const STARTER = [
        ['code' => 'wound_care', 'name' => 'Wound care', 'enforcement' => Competency::ENFORCEMENT_HARD],
        ['code' => 'catheter_care', 'name' => 'Catheter care', 'enforcement' => Competency::ENFORCEMENT_HARD],
        ['code' => 'injection', 'name' => 'Injections', 'enforcement' => Competency::ENFORCEMENT_HARD],
        ['code' => 'dementia_care', 'name' => 'Dementia care', 'enforcement' => Competency::ENFORCEMENT_SOFT],
        ['code' => 'palliative', 'name' => 'Palliative care', 'enforcement' => Competency::ENFORCEMENT_SOFT],
    ];

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): Competency
    {
        $this->authorize($actor);

        $competency = Competency::query()->create([
            'code' => $this->requireCode($data),
            'name' => $this->requireName($data),
            'description' => $this->nullableString($data['description'] ?? null),
            'enforcement' => $this->requireEnforcement($data),
            'active' => true,
        ]);

        Event::dispatch(new CompetencyChanged($competency, 'competency.defined', [
            'code' => $competency->code,
            'enforcement' => $competency->enforcement,
            'active' => $competency->active,
        ], $actor));

        return $competency;
    }

    /**
     * Update a definition. An enforcement change (hard <-> soft) is a dispatch-policy
     * change, so it is audited with a distinct action naming both sides.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Competency $competency, array $data, User $actor): Competency
    {
        $this->authorize($actor);

        $previousEnforcement = $competency->enforcement;

        if (array_key_exists('name', $data)) {
            $competency->name = $this->requireName($data);
        }

        if (array_key_exists('description', $data)) {
            $competency->description = $this->nullableString($data['description']);
        }

        if (array_key_exists('enforcement', $data)) {
            $competency->enforcement = $this->requireEnforcement($data);
        }

        if (array_key_exists('active', $data)) {
            $competency->active = (bool) $data['active'];
        }

        $competency->save();
        $competency->refresh();

        if ($competency->enforcement !== $previousEnforcement) {
            Event::dispatch(new CompetencyChanged($competency, 'competency.enforcement_changed', [
                'code' => $competency->code,
                'from_enforcement' => $previousEnforcement,
                'to_enforcement' => $competency->enforcement,
            ], $actor));
        } else {
            Event::dispatch(new CompetencyChanged($competency, 'competency.updated', [
                'code' => $competency->code,
                'enforcement' => $competency->enforcement,
                'active' => $competency->active,
            ], $actor));
        }

        return $competency;
    }

    /**
     * Grant (or re-grant) a competency to a nurse. Idempotent on the unique
     * (tenant, resource, competency) key — re-granting updates the expiry and
     * reactivates. `granted_at` defaults to today; `expires_at` is optional.
     *
     * @param  array{granted_at?: string|null, expires_at?: string|null}  $data
     */
    public function grant(Resource $resource, Competency $competency, array $data, User $actor): NurseCompetency
    {
        $this->authorize($actor);

        $grant = NurseCompetency::query()
            ->where('resource_id', $resource->id)
            ->where('competency_id', $competency->id)
            ->first() ?? new NurseCompetency;

        $grant->forceFill([
            'resource_id' => $resource->id,
            'competency_id' => $competency->id,
            'granted_at' => $data['granted_at'] ?? now()->toDateString(),
            'expires_at' => $data['expires_at'] ?? null,
            'active' => true,
        ])->save();

        $grant->refresh();

        Event::dispatch(new NurseCompetencyChanged($grant, 'nurse_competency.granted', [
            'resource_id' => $grant->resource_id,
            'competency_id' => $grant->competency_id,
            'competency_code' => $competency->code,
            'expires_at' => $grant->expires_at?->toDateString(),
        ], $actor));

        return $grant;
    }

    /**
     * Revoke a grant. The row is deactivated (not deleted) so the trail survives.
     */
    public function revoke(NurseCompetency $grant, User $actor): NurseCompetency
    {
        $this->authorize($actor);

        $grant->forceFill(['active' => false])->save();
        $grant->refresh();

        Event::dispatch(new NurseCompetencyChanged($grant, 'nurse_competency.revoked', [
            'resource_id' => $grant->resource_id,
            'competency_id' => $grant->competency_id,
        ], $actor));

        return $grant;
    }

    /**
     * Seed the generic starter template for the current tenant. Idempotent by code.
     */
    public function seedStarter(): int
    {
        $created = 0;

        foreach (self::STARTER as $item) {
            $model = Competency::query()->firstOrCreate(
                ['code' => $item['code']],
                [
                    'name' => $item['name'],
                    'enforcement' => $item['enforcement'],
                    'active' => true,
                ],
            );

            if ($model->wasRecentlyCreated) {
                $created++;
            }
        }

        return $created;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function requireCode(array $data): string
    {
        $code = trim((string) ($data['code'] ?? ''));

        if ($code === '') {
            throw new InvalidArgumentException('A competency needs a code.');
        }

        return $code;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function requireName(array $data): string
    {
        $name = trim((string) ($data['name'] ?? ''));

        if ($name === '') {
            throw new InvalidArgumentException('A competency needs a name.');
        }

        return $name;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function requireEnforcement(array $data): string
    {
        $enforcement = (string) ($data['enforcement'] ?? Competency::ENFORCEMENT_HARD);

        if (! in_array($enforcement, Competency::ENFORCEMENTS, true)) {
            throw new InvalidArgumentException('Competency enforcement must be hard or soft.');
        }

        return $enforcement;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function authorize(User $actor): void
    {
        if (! Gate::forUser($actor)->allows('competency.manage')) {
            throw new AuthorizationException('This user cannot manage nurse competencies.');
        }
    }
}
