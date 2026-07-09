<?php

namespace Modules\Nursing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Patients\Models\Patient;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Scheduling\Models\Resource;

/**
 * Nurse observational note captured during an executed visit.
 * This is not a signed/locked clinical SOAP note; countersigning is deferred.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $visit_id
 * @property string $patient_id
 * @property string $body
 * @property string $author_resource_id
 * @property Carbon $recorded_at
 */
class VisitNote extends Model
{
    use BelongsToTenant, HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'visit_id',
        'patient_id',
        'body',
        'author_resource_id',
        'recorded_at',
    ];

    protected static function booted(): void
    {
        static::saving(fn (VisitNote $note) => $note->assertTenantReferences());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
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

    public function authorResource(): BelongsTo
    {
        return $this->belongsTo(Resource::class, 'author_resource_id');
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

        if (! Resource::query()->whereKey($this->author_resource_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('author_resource_id', (string) $this->author_resource_id);
        }
    }
}
