<?php

namespace Modules\Billing\Services;

use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Modules\Audit\Services\AuditService;
use Modules\Billing\Models\Charge;
use Modules\Billing\Models\ChargeViolation;
use Modules\Billing\Models\TariffCatalog;
use Modules\Billing\Models\TariffItem;
use Modules\Clinical\Models\ClinicalNote;
use Modules\Nursing\Models\Visit;
use Modules\Patients\Models\Patient;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

class ChargeValidator
{
    public const RULE_MAX_QUANTITY_PER_PERIOD = 'MAX_QUANTITY_PER_PERIOD';

    public const RULE_INCOMPATIBLE_CODES = 'INCOMPATIBLE_CODES';

    public const RULE_REQUIRES_CODE = 'REQUIRES_CODE';

    public const RULE_DOCUMENTATION_REQUIRED = 'DOCUMENTATION_REQUIRED';

    public const REASON_MAX_QUANTITY_PER_PERIOD_EXCEEDED = 'MAX_QUANTITY_PER_PERIOD_EXCEEDED';

    public const REASON_INCOMPATIBLE_CODES_SAME_DATE = 'INCOMPATIBLE_CODES_SAME_DATE';

    public const REASON_REQUIRED_CODE_MISSING = 'REQUIRED_CODE_MISSING';

    public const REASON_DOCUMENTATION_REQUIRED_MISSING = 'DOCUMENTATION_REQUIRED_MISSING';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuditService $audit,
    ) {}

    /**
     * @return array{validated: list<string>, violations: list<array{charge_id: string, rule: string, reason_code: string, message: string}>}
     */
    public function validateForPatientPeriod(
        Patient $patient,
        CarbonInterface|string $from,
        CarbonInterface|string $to,
        User $actor,
    ): array {
        $this->assertSameTenant($patient, 'patient_id');

        $charges = Charge::query()
            ->where('patient_id', $patient->id)
            ->whereBetween('service_date', [
                $this->date($from),
                $this->date($to),
            ])
            ->whereIn('status', [Charge::STATUS_DRAFT, Charge::STATUS_VALIDATED])
            ->orderBy('service_date')
            ->orderBy('id')
            ->get();

        return $this->validateCharges($charges, $actor);
    }

    /**
     * @param  EloquentCollection<int, Charge>|Collection<int, Charge>  $charges
     * @return array{validated: list<string>, violations: list<array{charge_id: string, rule: string, reason_code: string, message: string}>}
     */
    public function validateCharges(EloquentCollection|Collection $charges, User $actor): array
    {
        $this->authorize($actor);
        $this->assertActorTenant($actor);

        $charges = $charges
            ->sortBy(fn (Charge $charge): string => $charge->service_date->toDateString().$charge->id)
            ->values();

        $charges->each(fn (Charge $charge) => $this->assertSameTenant($charge, 'charge_id'));

        $violations = $this->evaluate($charges);
        $byCharge = collect($violations)->groupBy('charge_id');

        DB::transaction(function () use ($charges, $byCharge, $actor): void {
            $charges->each(function (Charge $charge) use ($byCharge, $actor): void {
                /** @var Collection<int, array{charge_id: string, rule: string, reason_code: string, message: string, context: array<string, mixed>}> $desired */
                $desired = $byCharge->get($charge->id, collect());
                $desiredKeys = $desired
                    ->map(fn (array $violation): string => $this->violationKey($violation['rule'], $violation['reason_code']))
                    ->all();

                ChargeViolation::query()
                    ->where('charge_id', $charge->id)
                    ->get()
                    ->each(function (ChargeViolation $existing) use ($desiredKeys): void {
                        if (! in_array($this->violationKey($existing->rule, $existing->reason_code), $desiredKeys, true)) {
                            $existing->delete();
                        }
                    });

                $desired->each(function (array $violation) use ($charge, $actor): void {
                    $row = ChargeViolation::query()->firstOrCreate(
                        [
                            'charge_id' => $charge->id,
                            'rule' => $violation['rule'],
                            'reason_code' => $violation['reason_code'],
                        ],
                        [
                            'message' => $violation['message'],
                            'context' => $violation['context'],
                        ],
                    );

                    if ($row->wasRecentlyCreated) {
                        $this->auditCharge('charge.violation', $charge, $actor, [
                            'rule' => $violation['rule'],
                            'reason_code' => $violation['reason_code'],
                            'message' => $violation['message'],
                        ]);
                    }
                });

                if ($desired->isEmpty()) {
                    if ($charge->status !== Charge::STATUS_VALIDATED) {
                        $charge->forceFill(['status' => Charge::STATUS_VALIDATED])->save();
                        $this->auditCharge('charge.validated', $charge->refresh(), $actor);
                    }

                    return;
                }

                if ($charge->status === Charge::STATUS_VALIDATED) {
                    $charge->forceFill(['status' => Charge::STATUS_DRAFT])->save();
                }
            });
        });

        return [
            'validated' => $charges
                ->filter(fn (Charge $charge): bool => ! $byCharge->has($charge->id))
                ->pluck('id')
                ->values()
                ->all(),
            'violations' => collect($violations)
                ->map(fn (array $violation): array => [
                    'charge_id' => $violation['charge_id'],
                    'rule' => $violation['rule'],
                    'reason_code' => $violation['reason_code'],
                    'message' => $violation['message'],
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  Collection<int, Charge>  $charges
     * @return list<array{charge_id: string, rule: string, reason_code: string, message: string, context: array<string, mixed>}>
     */
    private function evaluate(Collection $charges): array
    {
        $violations = [];

        $charges
            ->groupBy('tariff_catalog_id')
            ->each(function (Collection $catalogCharges, string $catalogId) use (&$violations): void {
                $catalog = TariffCatalog::query()->whereKey($catalogId)->firstOrFail();

                foreach ($this->rules($catalog) as $rule) {
                    $violations = [
                        ...$violations,
                        ...$this->evaluateRule($catalogCharges, $rule),
                    ];
                }
            });

        return collect($violations)
            ->sortBy([
                ['charge_id', 'asc'],
                ['rule', 'asc'],
                ['reason_code', 'asc'],
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Charge>  $charges
     * @param  array<string, mixed>  $rule
     * @return list<array{charge_id: string, rule: string, reason_code: string, message: string, context: array<string, mixed>}>
     */
    private function evaluateRule(Collection $charges, array $rule): array
    {
        return match ($rule['type'] ?? null) {
            self::RULE_MAX_QUANTITY_PER_PERIOD => $this->maxQuantityPerPeriod($charges, $rule),
            self::RULE_INCOMPATIBLE_CODES => $this->incompatibleCodes($charges, $rule),
            self::RULE_REQUIRES_CODE => $this->requiresCode($charges, $rule),
            self::RULE_DOCUMENTATION_REQUIRED => $this->documentationRequired($charges, $rule),
            default => throw new InvalidArgumentException('Unsupported billing validation rule type.'),
        };
    }

    /**
     * @param  Collection<int, Charge>  $charges
     * @param  array<string, mixed>  $rule
     * @return list<array{charge_id: string, rule: string, reason_code: string, message: string, context: array<string, mixed>}>
     */
    private function maxQuantityPerPeriod(Collection $charges, array $rule): array
    {
        $code = (string) $rule['code'];
        $max = (int) $rule['max'];
        $period = (string) ($rule['period'] ?? 'day');

        $matches = $charges
            ->filter(fn (Charge $charge): bool => $charge->code === $code)
            ->groupBy(fn (Charge $charge): string => $this->periodKey($charge, $period));

        $violations = [];

        $matches->each(function (Collection $periodCharges, string $periodKey) use ($code, $max, $period, &$violations): void {
            $quantity = (int) $periodCharges->sum('quantity');

            if ($quantity <= $max) {
                return;
            }

            $periodCharges->each(function (Charge $charge) use ($code, $max, $period, $periodKey, $quantity, &$violations): void {
                $violations[] = $this->violation(
                    $charge,
                    self::RULE_MAX_QUANTITY_PER_PERIOD,
                    self::REASON_MAX_QUANTITY_PER_PERIOD_EXCEEDED,
                    "Code {$code} exceeds maximum quantity {$max} per {$period}.",
                    [
                        'code' => $code,
                        'max' => $max,
                        'period' => $period,
                        'period_key' => $periodKey,
                        'actual_quantity' => $quantity,
                    ],
                );
            });
        });

        return $violations;
    }

    /**
     * @param  Collection<int, Charge>  $charges
     * @param  array<string, mixed>  $rule
     * @return list<array{charge_id: string, rule: string, reason_code: string, message: string, context: array<string, mixed>}>
     */
    private function incompatibleCodes(Collection $charges, array $rule): array
    {
        $codes = array_values(array_map('strval', (array) $rule['codes']));
        [$first, $second] = [$codes[0], $codes[1]];
        $violations = [];

        $charges->groupBy(fn (Charge $charge): string => $charge->service_date->toDateString())
            ->each(function (Collection $dateCharges) use ($first, $second, &$violations): void {
                $hasFirst = $dateCharges->contains(fn (Charge $charge): bool => $charge->code === $first);
                $hasSecond = $dateCharges->contains(fn (Charge $charge): bool => $charge->code === $second);

                if (! $hasFirst || ! $hasSecond) {
                    return;
                }

                $dateCharges
                    ->filter(fn (Charge $charge): bool => in_array($charge->code, [$first, $second], true))
                    ->each(function (Charge $charge) use ($first, $second, &$violations): void {
                        $violations[] = $this->violation(
                            $charge,
                            self::RULE_INCOMPATIBLE_CODES,
                            self::REASON_INCOMPATIBLE_CODES_SAME_DATE,
                            "Codes {$first} and {$second} cannot both be billed on the same service date.",
                            ['codes' => [$first, $second]],
                        );
                    });
            });

        return $violations;
    }

    /**
     * @param  Collection<int, Charge>  $charges
     * @param  array<string, mixed>  $rule
     * @return list<array{charge_id: string, rule: string, reason_code: string, message: string, context: array<string, mixed>}>
     */
    private function requiresCode(Collection $charges, array $rule): array
    {
        $code = (string) $rule['code'];
        $required = (string) $rule['requires'];
        $violations = [];

        $charges->groupBy(fn (Charge $charge): string => $charge->service_date->toDateString())
            ->each(function (Collection $dateCharges) use ($code, $required, &$violations): void {
                $hasRequired = $dateCharges->contains(fn (Charge $charge): bool => $charge->code === $required);

                if ($hasRequired) {
                    return;
                }

                $dateCharges
                    ->filter(fn (Charge $charge): bool => $charge->code === $code)
                    ->each(function (Charge $charge) use ($code, $required, &$violations): void {
                        $violations[] = $this->violation(
                            $charge,
                            self::RULE_REQUIRES_CODE,
                            self::REASON_REQUIRED_CODE_MISSING,
                            "Code {$code} requires code {$required} on the same service date.",
                            ['code' => $code, 'requires' => $required],
                        );
                    });
            });

        return $violations;
    }

    /**
     * @param  Collection<int, Charge>  $charges
     * @param  array<string, mixed>  $rule
     * @return list<array{charge_id: string, rule: string, reason_code: string, message: string, context: array<string, mixed>}>
     */
    private function documentationRequired(Collection $charges, array $rule): array
    {
        $codes = array_key_exists('codes', $rule)
            ? array_values(array_map('strval', (array) $rule['codes']))
            : [];

        return $charges
            ->filter(fn (Charge $charge): bool => $codes === [] || in_array($charge->code, $codes, true))
            ->filter(fn (Charge $charge): bool => $this->chargeRequiresDocumentation($charge))
            ->reject(fn (Charge $charge): bool => $this->hasCurrentDocumentation($charge))
            ->map(fn (Charge $charge): array => $this->violation(
                $charge,
                self::RULE_DOCUMENTATION_REQUIRED,
                self::REASON_DOCUMENTATION_REQUIRED_MISSING,
                "Code {$charge->code} requires a signed encounter note or completed visit.",
                ['code' => $charge->code],
            ))
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rules(TariffCatalog $catalog): array
    {
        $rules = $catalog->rules ?? [];

        if (array_key_exists('validation_rules', $rules)) {
            return array_values((array) $rules['validation_rules']);
        }

        return array_values((array) $rules);
    }

    private function periodKey(Charge $charge, string $period): string
    {
        $date = $charge->service_date->copy();

        return match ($period) {
            'day' => $date->toDateString(),
            'week' => $date->startOfWeek()->toDateString(),
            'month' => $date->format('Y-m'),
            default => throw new InvalidArgumentException("Unsupported billing validation period [{$period}]."),
        };
    }

    private function chargeRequiresDocumentation(Charge $charge): bool
    {
        $item = TariffItem::query()->whereKey($charge->tariff_item_id)->first();

        return $item instanceof TariffItem && $item->requires_service_documentation;
    }

    private function hasCurrentDocumentation(Charge $charge): bool
    {
        if ($charge->encounter_id !== null) {
            return ClinicalNote::query()
                ->where('encounter_id', $charge->encounter_id)
                ->where('status', ClinicalNote::STATUS_SIGNED)
                ->exists();
        }

        if ($charge->visit_id !== null) {
            $visit = Visit::query()->whereKey($charge->visit_id)->first();

            return $visit instanceof Visit && $visit->status === Visit::STATUS_COMPLETED;
        }

        return false;
    }

    /**
     * @return array{charge_id: string, rule: string, reason_code: string, message: string, context: array<string, mixed>}
     */
    private function violation(Charge $charge, string $rule, string $reasonCode, string $message, array $context): array
    {
        return [
            'charge_id' => $charge->id,
            'rule' => $rule,
            'reason_code' => $reasonCode,
            'message' => $message,
            'context' => $context,
        ];
    }

    private function date(CarbonInterface|string $date): string
    {
        return $date instanceof CarbonInterface
            ? Carbon::instance($date)->toDateString()
            : Carbon::parse($date)->toDateString();
    }

    private function violationKey(string $rule, string $reasonCode): string
    {
        return "{$rule}:{$reasonCode}";
    }

    private function authorize(User $actor): void
    {
        if (! Gate::forUser($actor)->allows('billing.manage')) {
            throw new AuthorizationException('This user cannot manage billing.');
        }
    }

    private function assertActorTenant(User $actor): void
    {
        if ($actor->tenant_id !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute('actor_id', (string) $actor->id);
        }
    }

    private function assertSameTenant(object $model, string $attribute): void
    {
        if (($model->tenant_id ?? null) !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute($attribute, (string) ($model->id ?? ''));
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function auditCharge(string $action, Charge $charge, User $actor, array $context = []): void
    {
        $this->audit->record([
            'actor_type' => 'user',
            'actor_id' => (string) $actor->id,
            'action' => $action,
            'patient_id' => $charge->patient_id,
            'resource_type' => 'charge',
            'resource_id' => $charge->id,
            'context' => [
                'code' => $charge->code,
                'service_date' => $charge->service_date->toDateString(),
                'status' => $charge->status,
                ...$context,
            ],
        ]);
    }
}
