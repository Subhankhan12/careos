<?php

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * Per-tenant, per-series gapless invoice number sequence.
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $series
 * @property int $next_number
 */
class InvoiceSequence extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'series',
        'next_number',
    ];

    protected $attributes = [
        'series' => Invoice::SERIES_INVOICE,
        'next_number' => 1,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'next_number' => 'integer',
        ];
    }
}
