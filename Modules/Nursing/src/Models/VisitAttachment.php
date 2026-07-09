<?php

namespace Modules\Nursing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Modules\Patients\Models\Patient;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $visit_id
 * @property string $patient_id
 * @property string $type
 * @property string $storage_path
 * @property string $mime_type
 * @property int $size_bytes
 * @property Carbon $captured_at
 */
class VisitAttachment extends Model
{
    use BelongsToTenant, HasUlids;

    public const TYPE_PHOTO = 'photo';

    public const TYPE_SIGNATURE = 'signature';

    public const TYPES = [
        self::TYPE_PHOTO,
        self::TYPE_SIGNATURE,
    ];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'visit_id',
        'patient_id',
        'type',
        'storage_path',
        'mime_type',
        'size_bytes',
        'captured_at',
    ];

    protected static function booted(): void
    {
        static::saving(function (VisitAttachment $attachment): void {
            $attachment->assertTenantReferences();
            $attachment->assertType();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'captured_at' => 'datetime',
        ];
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    private function assertTenantReferences(): void
    {
        $visit = Visit::query()->whereKey($this->visit_id)->first();
        if ($visit === null || $visit->patient_id !== $this->patient_id) {
            throw CrossTenantReferenceException::forAttribute('visit_id', (string) $this->visit_id);
        }

        if (! Patient::query()->whereKey($this->patient_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('patient_id', (string) $this->patient_id);
        }
    }

    private function assertType(): void
    {
        if (! in_array($this->type, self::TYPES, true)) {
            throw new InvalidArgumentException('Visit attachment type is not valid.');
        }
    }
}
