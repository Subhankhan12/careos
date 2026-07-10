<?php

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;

/**
 * Tenant-owned append-only record that a dunning level fired for an invoice.
 *
 * The status is decided at insert time (`created` when only the letter was
 * rendered, `sent` when delivery succeeded) and never changes: the row is an
 * immutable historical fact, guarded at model and DB-trigger levels.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $invoice_id
 * @property int $level
 * @property Carbon $triggered_on
 * @property string|null $document_path
 * @property string $status
 */
class DunningEvent extends Model
{
    use BelongsToTenant, HasUlids;

    public const STATUS_CREATED = 'created';

    public const STATUS_SENT = 'sent';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'invoice_id',
        'level',
        'triggered_on',
        'document_path',
        'status',
    ];

    protected $attributes = [
        'status' => self::STATUS_CREATED,
    ];

    protected static function booted(): void
    {
        static::creating(fn (DunningEvent $event) => $event->assertTenantReferences());
        static::updating(function (): void {
            throw new LogicException('dunning_events are append-only: they cannot be updated.');
        });
        static::deleting(function (): void {
            throw new LogicException('dunning_events are append-only: they cannot be deleted.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'triggered_on' => 'date',
            'level' => 'integer',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    private function assertTenantReferences(): void
    {
        if (! Invoice::query()->whereKey($this->invoice_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('invoice_id', (string) $this->invoice_id);
        }
    }
}
