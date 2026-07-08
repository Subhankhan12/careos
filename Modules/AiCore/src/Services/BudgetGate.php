<?php

namespace Modules\AiCore\Services;

use Illuminate\Support\Carbon;
use Modules\AiCore\Exceptions\AiCoreException;
use Modules\AiCore\Models\AiInteraction;
use Modules\Platform\Services\SettingsService;
use Modules\Platform\Services\TenantContext;

class BudgetGate
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly TenantContext $tenantContext,
    ) {}

    public function assertWithinBudget(int $estimatedCostMinor): void
    {
        $budget = (int) $this->settings->get('ai.monthly_budget_minor', config('aicore.default_monthly_budget_minor'));
        $spent = $this->spentThisMonth();

        if ($budget < 0 || $spent + $estimatedCostMinor > $budget) {
            throw new AiCoreException('AI budget exhausted; route to manual workflow.');
        }
    }

    public function spentThisMonth(): int
    {
        $start = Carbon::now()->startOfMonth();

        return (int) AiInteraction::query()
            ->where('tenant_id', $this->tenantContext->id())
            ->where('occurred_at', '>=', $start)
            ->sum('cost_minor');
    }
}
