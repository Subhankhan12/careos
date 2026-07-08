<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Platform\Models\Plan;

class PlanCatalogSeeder extends Seeder
{
    /**
     * Starter plans. price_minor is in integer minor units (cents).
     *
     * @var list<array{key: string, name: string, price_minor: int, limits: array<string, int>, features: array<string, bool>}>
     */
    public const PLANS = [
        [
            'key' => 'eu_starter',
            'name' => 'EU Starter',
            'price_minor' => 4900,
            'limits' => ['max_branches' => 1, 'max_staff' => 10],
            'features' => ['telehealth' => false, 'evv' => false, 'ai_drafting' => false],
        ],
        [
            'key' => 'eu_pro',
            'name' => 'EU Pro',
            'price_minor' => 19900,
            'limits' => ['max_branches' => 10, 'max_staff' => 100],
            'features' => ['telehealth' => true, 'evv' => false, 'ai_drafting' => true],
        ],
    ];

    public function run(): void
    {
        foreach (self::PLANS as $plan) {
            Plan::query()->updateOrCreate(['key' => $plan['key']], $plan);
        }
    }
}
