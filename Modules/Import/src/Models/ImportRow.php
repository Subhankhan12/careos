<?php

namespace Modules\Import\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * One source row of an import batch and its dry-run / commit outcome.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $import_batch_id
 * @property int $row_number
 * @property array<string, mixed> $raw
 * @property string $status
 * @property array<string, string>|null $errors
 * @property string|null $matched_patient_id
 * @property array<string, mixed>|null $match
 * @property string|null $created_entity_id
 */
class ImportRow extends Model
{
    use BelongsToTenant, HasUlids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_VALID = 'valid';

    public const STATUS_INVALID = 'invalid';

    public const STATUS_DUPLICATE = 'duplicate';

    public const STATUS_IMPORTED = 'imported';

    public const STATUS_SKIPPED = 'skipped';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'import_batch_id',
        'row_number',
        'raw',
        'status',
        'errors',
        'matched_patient_id',
        'match',
        'created_entity_id',
    ];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'row_number' => 'integer',
            'raw' => 'array',
            'errors' => 'array',
            'match' => 'array',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }
}
