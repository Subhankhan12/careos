<?php

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Billing\Services\PaymentPlanService;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Models\User;

/**
 * ARDETAIL.P5 — an installment plan covering part or all of an account's REAL outstanding balance.
 *
 * A plan SCHEDULES money; it never moves any. `total_minor` may never exceed the account's actual
 * outstanding (enforced in {@see PaymentPlanService::create()}, which also forbids a second active
 * plan on the account) and the installments are an exact PARTITION of it — so a plan can never
 * represent money that is not owed. Settling an installment goes through the guarded
 * {@see PaymentService} (ARDETAIL.P4); this model records the schedule and its outcome only.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $patient_id
 * @property int $total_minor
 * @property string $currency
 * @property int $installment_count
 * @property Carbon $start_date
 * @property string $status
 * @property int $outstanding_at_creation_minor
 * @property string|null $closed_reason
 * @property Carbon|null $closed_at
 * @property int $created_by
 * @property Carbon $agreed_at
 */
class PaymentPlan extends Model
{
    use BelongsToTenant, HasUlids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_DEFAULTED = 'defaulted';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_COMPLETED, self::STATUS_CANCELLED, self::STATUS_DEFAULTED];

    /** A plan that is still running (the only state in which installments may be settled). */
    public const OPEN_STATUSES = [self::STATUS_ACTIVE];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'patient_id',
        'total_minor',
        'currency',
        'installment_count',
        'start_date',
        'status',
        'outstanding_at_creation_minor',
        'closed_reason',
        'closed_at',
        'created_by',
        'agreed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_minor' => 'integer',
            'installment_count' => 'integer',
            'outstanding_at_creation_minor' => 'integer',
            'start_date' => 'date',
            'closed_at' => 'datetime',
            'agreed_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function installments(): HasMany
    {
        return $this->hasMany(PaymentPlanInstallment::class)->orderBy('sequence');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
