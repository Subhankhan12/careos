<?php

namespace Modules\Clinical\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Modules\Clinical\Models\OrderableItem;
use Modules\Platform\Models\User;

/**
 * Manages a tenant's OWN orderable-item list. There is NO bundled licensed
 * catalog — a tenant authors its own tests/studies (like the tariff catalog).
 */
class OrderableItemService
{
    /**
     * A small generic starter set, offered as an EDITABLE TEMPLATE — not a
     * licensed code set. Each tenant may edit/extend/replace it.
     *
     * @var list<array{category: string, code: string, name: string, specimen_or_modality: string}>
     */
    private const STARTER = [
        ['category' => 'lab', 'code' => 'FBC', 'name' => 'Full blood count', 'specimen_or_modality' => 'Blood'],
        ['category' => 'lab', 'code' => 'UE', 'name' => 'Urea & electrolytes', 'specimen_or_modality' => 'Blood'],
        ['category' => 'lab', 'code' => 'URINALYSIS', 'name' => 'Urinalysis', 'specimen_or_modality' => 'Urine'],
        ['category' => 'imaging', 'code' => 'CXR', 'name' => 'Chest X-ray', 'specimen_or_modality' => 'X-ray'],
        ['category' => 'imaging', 'code' => 'USS-ABDO', 'name' => 'Abdominal ultrasound', 'specimen_or_modality' => 'Ultrasound'],
    ];

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): OrderableItem
    {
        $this->authorize($actor);

        $category = (string) ($data['category'] ?? '');
        if (! in_array($category, OrderableItem::CATEGORIES, true)) {
            throw new InvalidArgumentException('Unknown orderable category.');
        }

        $code = trim((string) ($data['code'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        if ($code === '' || $name === '') {
            throw new InvalidArgumentException('An orderable item needs a code and a name.');
        }

        return OrderableItem::query()->create([
            'category' => $category,
            'code' => $code,
            'name' => $name,
            'specimen_or_modality' => $data['specimen_or_modality'] ?? null,
            'active' => true,
        ]);
    }

    public function deactivate(OrderableItem $item, User $actor): OrderableItem
    {
        $this->authorize($actor);
        $item->forceFill(['active' => false])->save();

        return $item->refresh();
    }

    /**
     * Seed the generic starter template for the current tenant. Idempotent by code.
     */
    public function seedStarter(): int
    {
        $created = 0;

        foreach (self::STARTER as $item) {
            $model = OrderableItem::query()->firstOrCreate(
                ['code' => $item['code']],
                [
                    'category' => $item['category'],
                    'name' => $item['name'],
                    'specimen_or_modality' => $item['specimen_or_modality'],
                    'active' => true,
                ],
            );

            if ($model->wasRecentlyCreated) {
                $created++;
            }
        }

        return $created;
    }

    private function authorize(User $actor): void
    {
        if (! Gate::forUser($actor)->allows('order.manage')) {
            throw new AuthorizationException('This user cannot manage orderable items.');
        }
    }
}
