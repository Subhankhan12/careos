<?php

namespace Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Audit\Concerns\LogsReads;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * An append-only order result. Stored RAW: result_value only, with NO
 * interpretation fields (no range/flag/abnormal/score). Corrections are new
 * result rows, never edits (DB triggers block UPDATE/DELETE).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $order_id
 * @property string $patient_id
 * @property string|null $result_value
 * @property string|null $result_document_id
 * @property int $entered_by
 * @property Carbon $entered_at
 * @property string $source
 */
class OrderResult extends Model
{
    use BelongsToTenant, HasUlids, LogsReads;

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_IMPORTED = 'imported';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'order_id',
        'patient_id',
        'result_value',
        'result_document_id',
        'entered_by',
        'entered_at',
        'source',
    ];

    protected $attributes = [
        'source' => self::SOURCE_MANUAL,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['entered_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    protected function auditResourceType(): string
    {
        return 'order_result';
    }

    protected function auditPatientId(): ?string
    {
        return $this->patient_id;
    }
}
