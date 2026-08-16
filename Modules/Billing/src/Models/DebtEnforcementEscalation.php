<?php

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Models\User;

/**
 * ARDETAIL.P6 — an APPEND-ONLY record that a HUMAN OPERATOR escalated an account to debt enforcement
 * (Swiss *Betreibung*), or withdrew such an escalation.
 *
 * `initiated_by` is a real {@see User} and is never null — a legal proceeding always names the person
 * who started it. There is no system/agent actor path to this model: the AI agent may draft dunning
 * reminders through the ApprovalQueue, but it can neither construct nor persist an escalation
 * ("0 auto-escalated" holds by construction, not by a setting).
 *
 * Never edited or deleted (ORM guards + DB triggers): withdrawing appends a NEW row whose
 * `supersedes_escalation_id` points at the escalation it ends.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $patient_id
 * @property string $action
 * @property string|null $supersedes_escalation_id
 * @property int $outstanding_minor
 * @property string $currency
 * @property int $dunning_stage
 * @property string $reason
 * @property string|null $reference
 * @property int $initiated_by
 * @property Carbon $confirmed_at
 * @property Carbon $initiated_at
 */
class DebtEnforcementEscalation extends Model
{
    use BelongsToTenant, HasUlids;

    public const ACTION_INITIATED = 'initiated';

    public const ACTION_WITHDRAWN = 'withdrawn';

    public const ACTIONS = [self::ACTION_INITIATED, self::ACTION_WITHDRAWN];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'patient_id',
        'action',
        'supersedes_escalation_id',
        'outstanding_minor',
        'currency',
        'dunning_stage',
        'reason',
        'reference',
        'initiated_by',
        'confirmed_at',
        'initiated_at',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('debt_enforcement_escalations are append-only: they cannot be updated. Withdraw by appending a new record.');
        });
        static::deleting(function (): void {
            throw new LogicException('debt_enforcement_escalations are append-only: they cannot be deleted.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'outstanding_minor' => 'integer',
            'dunning_stage' => 'integer',
            'confirmed_at' => 'datetime',
            'initiated_at' => 'datetime',
        ];
    }

    public function isWithdrawal(): bool
    {
        return $this->action === self::ACTION_WITHDRAWN;
    }

    /** The operator who performed this act — never a system or agent actor. */
    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }
}
