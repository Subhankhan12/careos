<?php

namespace Modules\Hospital\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\Branch;

/**
 * An inpatient bed (HOSPITAL.G1) — a NET-NEW model, deliberately NOT a Scheduling
 * Resource (docs/HOSPITAL-PHASE1-ADT-MAP.md §2.1). A bed is occupied CONTINUOUSLY
 * for a multi-day stay, so there is NO starts_at/ends_at — occupancy is a stay
 * (HOSPITAL.G2), not a timed slot. What this model reuses is the Resource *pattern*
 * (tenant + one branch + typed enum + active flag) and — via BedService — the
 * BookingService lock-row-then-assert idiom for a concurrency-safe claim.
 *
 * `status` is a purely OPERATIONAL housekeeping state that can be true with NO
 * patient/service attached (e.g. `cleaning`). ELECTRIC FENCE: there is deliberately
 * NO patient / acuity / severity / score / risk / flag field here — a bed records
 * housekeeping facts, never a clinical judgment.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $branch_id
 * @property string $ward_id
 * @property string $label
 * @property string $bed_type
 * @property string $status
 * @property bool $active
 */
class Bed extends Model
{
    use BelongsToTenant, HasUlids;

    // Bed type — tenant-meaningful physical categories (not clinical acuity).
    public const TYPE_GENERAL = 'general';

    public const TYPE_ICU = 'icu';

    public const TYPE_ISOLATION = 'isolation';

    public const TYPES = [self::TYPE_GENERAL, self::TYPE_ICU, self::TYPE_ISOLATION];

    // Housekeeping status — operational bed state, never a patient judgment.
    public const STATUS_FREE = 'free';

    public const STATUS_OCCUPIED = 'occupied';

    public const STATUS_CLEANING = 'cleaning';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUSES = [self::STATUS_FREE, self::STATUS_OCCUPIED, self::STATUS_CLEANING, self::STATUS_BLOCKED];

    /**
     * The legal housekeeping transitions. free -> occupied is reached ONLY through
     * the concurrency-safe BedService::claim() primitive (never a plain setStatus),
     * so a bed can only be occupied by one winner under a row lock.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::STATUS_FREE => [self::STATUS_OCCUPIED, self::STATUS_BLOCKED],
        self::STATUS_OCCUPIED => [self::STATUS_CLEANING],
        self::STATUS_CLEANING => [self::STATUS_FREE, self::STATUS_BLOCKED],
        self::STATUS_BLOCKED => [self::STATUS_FREE],
    ];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'branch_id',
        'ward_id',
        'label',
        'bed_type',
        'active',
    ];

    protected $attributes = [
        'status' => self::STATUS_FREE,
        'active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Bed $bed): void {
            $bed->assertReferencesWithinTenant();
        });

        static::updating(function (Bed $bed): void {
            if ($bed->isDirty(['branch_id', 'ward_id'])) {
                $bed->assertReferencesWithinTenant();
            }
        });
    }

    /**
     * Whether a housekeeping transition from -> to is legal per {@see self::TRANSITIONS}.
     */
    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function ward(): BelongsTo
    {
        return $this->belongsTo(Ward::class);
    }

    /**
     * Branch + ward lookups are tenant-scoped by BelongsToTenant, so a branch/ward
     * owned by another tenant is invisible here and rejected as a cross-tenant link.
     */
    private function assertReferencesWithinTenant(): void
    {
        if (! empty($this->branch_id) && ! Branch::whereKey($this->branch_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('branch_id', (string) $this->branch_id);
        }

        if (! empty($this->ward_id) && ! Ward::whereKey($this->ward_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('ward_id', (string) $this->ward_id);
        }
    }
}
