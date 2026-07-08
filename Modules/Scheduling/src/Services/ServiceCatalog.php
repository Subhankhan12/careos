<?php

namespace Modules\Scheduling\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\Branch;
use Modules\Scheduling\Models\Service;
use Modules\Scheduling\Models\ServiceBranch;

class ServiceCatalog
{
    /**
     * @return Collection<int, Service>
     */
    public function list(): Collection
    {
        return Service::query()
            ->orderBy('name')
            ->with('branchLinks')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $branchIds
     */
    public function create(array $attributes, array $branchIds = []): Service
    {
        $this->validateAttributes($attributes);
        $this->assertCodeIsUnique((string) $attributes['code']);
        $branchIds = $this->validateBranchIds($branchIds);

        return DB::transaction(function () use ($attributes, $branchIds): Service {
            $service = Service::create($attributes);
            $this->replaceBranchLinks($service, $branchIds);

            return $service->load('branchLinks');
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<string>|null  $branchIds
     */
    public function update(Service $service, array $attributes, ?array $branchIds = null): Service
    {
        $this->validateAttributes($attributes, updating: true);

        if (array_key_exists('code', $attributes)) {
            $this->assertCodeIsUnique((string) $attributes['code'], $service);
        }

        $validatedBranchIds = $branchIds === null ? null : $this->validateBranchIds($branchIds);

        return DB::transaction(function () use ($service, $attributes, $validatedBranchIds): Service {
            $service->update($attributes);

            if ($validatedBranchIds !== null) {
                $this->replaceBranchLinks($service, $validatedBranchIds);
            }

            return $service->refresh()->load('branchLinks');
        });
    }

    public function delete(Service $service): void
    {
        DB::transaction(function () use ($service): void {
            $service->branchLinks()->delete();
            $service->delete();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function validateAttributes(array $attributes, bool $updating = false): void
    {
        foreach (['name', 'code', 'default_duration_minutes', 'requires_resource_types'] as $required) {
            if (! $updating && ! array_key_exists($required, $attributes)) {
                throw new InvalidArgumentException("Service {$required} is required.");
            }
        }

        if (array_key_exists('name', $attributes) && trim((string) $attributes['name']) === '') {
            throw new InvalidArgumentException('Service name is required.');
        }

        if (array_key_exists('code', $attributes) && trim((string) $attributes['code']) === '') {
            throw new InvalidArgumentException('Service code is required.');
        }

        if (
            array_key_exists('default_duration_minutes', $attributes)
            && (int) $attributes['default_duration_minutes'] <= 0
        ) {
            throw new InvalidArgumentException('Service duration must be greater than zero.');
        }

        foreach (['buffer_before_minutes', 'buffer_after_minutes'] as $buffer) {
            if (array_key_exists($buffer, $attributes) && (int) $attributes[$buffer] < 0) {
                throw new InvalidArgumentException('Service buffers must be zero or greater.');
            }
        }

        if (array_key_exists('requires_resource_types', $attributes)) {
            $resourceTypes = $attributes['requires_resource_types'];

            if (! is_array($resourceTypes) || $resourceTypes === []) {
                throw new InvalidArgumentException('At least one resource type is required.');
            }

            foreach ($resourceTypes as $resourceType) {
                if (! is_string($resourceType) || trim($resourceType) === '') {
                    throw new InvalidArgumentException('Resource types must be non-empty strings.');
                }
            }
        }
    }

    private function assertCodeIsUnique(string $code, ?Service $ignore = null): void
    {
        $query = Service::where('code', $code);

        if ($ignore !== null) {
            $query->whereKeyNot($ignore->id);
        }

        if ($query->exists()) {
            throw new InvalidArgumentException("Service code [{$code}] already exists for this tenant.");
        }
    }

    /**
     * @param  list<string>  $branchIds
     * @return list<string>
     */
    private function validateBranchIds(array $branchIds): array
    {
        $branchIds = array_values(array_unique($branchIds));

        foreach ($branchIds as $branchId) {
            if (! Branch::whereKey($branchId)->exists()) {
                throw CrossTenantReferenceException::forAttribute('branch_id', (string) $branchId);
            }
        }

        return $branchIds;
    }

    /**
     * @param  list<string>  $branchIds
     */
    private function replaceBranchLinks(Service $service, array $branchIds): void
    {
        $service->branchLinks()->delete();

        foreach ($branchIds as $branchId) {
            ServiceBranch::create([
                'service_id' => $service->id,
                'branch_id' => $branchId,
            ]);
        }
    }
}
