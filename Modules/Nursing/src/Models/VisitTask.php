<?php

namespace Modules\Nursing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $visit_id
 * @property string|null $agreement_service_id
 * @property string $description
 * @property string $status
 * @property string|null $not_done_reason
 * @property Carbon|null $completed_at
 */
class VisitTask extends Model
{
    use BelongsToTenant, HasUlids;

    public const STATUS_OPEN = 'open';

    public const STATUS_DONE = 'done';

    public const STATUS_NOT_DONE = 'not_done';

    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_DONE,
        self::STATUS_NOT_DONE,
    ];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'visit_id',
        'agreement_service_id',
        'description',
        'status',
        'not_done_reason',
        'completed_at',
    ];

    protected $attributes = [
        'status' => self::STATUS_OPEN,
    ];

    protected static function booted(): void
    {
        static::saving(function (VisitTask $task): void {
            $task->assertTenantReferences();
            $task->assertStatus();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
        ];
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function agreementService(): BelongsTo
    {
        return $this->belongsTo(AgreementService::class);
    }

    private function assertTenantReferences(): void
    {
        if (! Visit::query()->whereKey($this->visit_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('visit_id', (string) $this->visit_id);
        }

        if ($this->agreement_service_id !== null && ! AgreementService::query()->whereKey($this->agreement_service_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('agreement_service_id', (string) $this->agreement_service_id);
        }
    }

    private function assertStatus(): void
    {
        if (! in_array($this->status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Visit task status is not valid.');
        }

        if ($this->status === self::STATUS_NOT_DONE && trim((string) $this->not_done_reason) === '') {
            throw new InvalidArgumentException('A not-done visit task requires a reason.');
        }
    }
}
