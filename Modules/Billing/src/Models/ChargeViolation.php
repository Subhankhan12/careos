<?php

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;

/**
 * Tenant-owned validation finding attached to a charge.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $charge_id
 * @property string $rule
 * @property string $reason_code
 * @property string $message
 * @property array<string, mixed>|null $context
 */
class ChargeViolation extends Model
{
    use BelongsToTenant, HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'charge_id',
        'rule',
        'reason_code',
        'message',
        'context',
    ];

    protected static function booted(): void
    {
        static::creating(fn (ChargeViolation $violation) => $violation->assertTenantReferences());
        static::updating(function (ChargeViolation $violation): void {
            if ($violation->isDirty('charge_id')) {
                $violation->assertTenantReferences();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'context' => 'array',
        ];
    }

    public function charge(): BelongsTo
    {
        return $this->belongsTo(Charge::class);
    }

    private function assertTenantReferences(): void
    {
        if (! Charge::query()->whereKey($this->charge_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('charge_id', (string) $this->charge_id);
        }
    }
}
