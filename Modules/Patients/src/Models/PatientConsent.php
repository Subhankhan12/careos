<?php

namespace Modules\Patients\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

/**
 * Captured patient consent, including an immutable snapshot of signed template text.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $patient_id
 * @property string $template_id
 * @property int $template_version
 * @property string $template_key
 * @property string $template_title
 * @property string $template_body
 * @property list<string> $template_scope_keys
 * @property string $status
 * @property array<string, mixed> $signature
 * @property int $captured_by
 */
class PatientConsent extends Model
{
    use BelongsToTenant, HasUlids;

    public const STATUS_GRANTED = 'granted';

    public const STATUS_WITHDRAWN = 'withdrawn';

    public const STATUS_EXPIRED = 'expired';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'patient_id',
        'template_id',
        'template_version',
        'template_key',
        'template_title',
        'template_body',
        'template_scope_keys',
        'status',
        'granted_at',
        'withdrawn_at',
        'expires_at',
        'signature',
        'captured_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'template_scope_keys' => 'array',
            'signature' => 'array',
            'granted_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (PatientConsent $consent) => $consent->assertReferencesWithinTenant());
        static::updating(function (PatientConsent $consent): void {
            if ($consent->isDirty(['patient_id', 'template_id', 'captured_by'])) {
                $consent->assertReferencesWithinTenant();
            }
        });
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ConsentTemplate::class, 'template_id');
    }

    public function capturedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captured_by');
    }

    private function assertReferencesWithinTenant(): void
    {
        if (! Patient::whereKey($this->patient_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('patient_id', (string) $this->patient_id);
        }

        if (! ConsentTemplate::whereKey($this->template_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('template_id', (string) $this->template_id);
        }

        $tenantId = app(TenantContext::class)->id();
        $capturedBy = User::query()->find($this->captured_by);

        if ($capturedBy === null || $capturedBy->tenant_id !== $tenantId) {
            throw CrossTenantReferenceException::forAttribute('captured_by', (string) $this->captured_by);
        }
    }
}
