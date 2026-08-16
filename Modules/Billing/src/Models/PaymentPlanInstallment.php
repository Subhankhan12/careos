<?php

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * ARDETAIL.P5 — one scheduled installment of a {@see PaymentPlan}.
 *
 * A SCHEDULE line, not a money movement: the amounts of a plan's installments sum EXACTLY to the
 * plan's `total_minor` (the last one absorbs the integer remainder), and settling one records a real
 * payment through the guarded {@see PaymentService} (ARDETAIL.P4) whose id is stored in `payment_id`.
 * The row therefore holds no allocation, balance or derived total — the reconciling ledger remains
 * the single source of truth for money.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $payment_plan_id
 * @property int $sequence
 * @property Carbon $due_date
 * @property int $amount_minor
 * @property string $status
 * @property string|null $payment_id
 * @property Carbon|null $paid_at
 */
class PaymentPlanInstallment extends Model
{
    use BelongsToTenant, HasUlids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'payment_plan_id',
        'sequence',
        'due_date',
        'amount_minor',
        'status',
        'payment_id',
        'paid_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'amount_minor' => 'integer',
            'due_date' => 'date',
            'paid_at' => 'datetime',
        ];
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    /**
     * OVERDUE is DERIVED from recorded facts (still pending, due date in the past) — never a stored
     * status that could drift out of step with the schedule.
     */
    public function isOverdue(Carbon $asOf): bool
    {
        return ! $this->isPaid() && $this->due_date->startOfDay()->lt($asOf->copy()->startOfDay());
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PaymentPlan::class, 'payment_plan_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }
}
