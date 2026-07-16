<?php

namespace Modules\Import\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * A tenant-owned CSV import batch: an uploaded file, its column mapping, the
 * dry-run summary, and (once committed) the outcome.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $type
 * @property string $original_filename
 * @property string $storage_path
 * @property string $status
 * @property int $row_count
 * @property string|null $date_format
 * @property string|null $duplicate_policy
 * @property array<string, string>|null $mapping
 * @property array<string, mixed>|null $summary
 * @property string|null $created_by
 * @property Carbon|null $committed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ImportBatch extends Model
{
    use BelongsToTenant, HasUlids;

    public const TYPE_PATIENTS = 'patients';

    public const STATUS_UPLOADED = 'uploaded';

    public const STATUS_MAPPED = 'mapped';

    public const STATUS_VALIDATED = 'validated';

    public const STATUS_COMMITTED = 'committed';

    public const STATUS_FAILED = 'failed';

    public const POLICY_SKIP = 'skip';

    public const POLICY_IMPORT_AS_NEW = 'import_as_new';

    public const POLICY_MERGE = 'merge';

    public const POLICIES = [self::POLICY_SKIP, self::POLICY_IMPORT_AS_NEW, self::POLICY_MERGE];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'type',
        'original_filename',
        'storage_path',
        'status',
        'row_count',
        'date_format',
        'duplicate_policy',
        'mapping',
        'summary',
        'created_by',
        'committed_at',
    ];

    protected $attributes = [
        'type' => self::TYPE_PATIENTS,
        'status' => self::STATUS_UPLOADED,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'row_count' => 'integer',
            'mapping' => 'array',
            'summary' => 'array',
            'committed_at' => 'datetime',
        ];
    }

    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class);
    }
}
