<?php

namespace Modules\Hospital\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Clinical\Models\Encounter;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;

/**
 * The Hospital-SIDE link tying an inpatient Stay to a Clinical Encounter created during it
 * (a "ward round" — HOSPITAL.G4). The map's §2.2 "Stay -> per-round Encounter" decomposition:
 * Clinical's Encounter is REUSED UNMODIFIED (its schema + one-open-per-practitioner invariant
 * are untouched); this link keeps the association Hospital-side. Bedside notes/vitals/orders
 * hang off the Encounter through the existing Clinical services.
 *
 * Hospital may use Clinical (not forbidden by the module boundary) — the same allowed dependency
 * Dental uses for documents/encounters.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $stay_id
 * @property string $encounter_id
 * @property-read Stay|null $stay
 * @property-read Encounter|null $encounter
 */
class WardRound extends Model
{
    use BelongsToTenant, HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'stay_id',
        'encounter_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (WardRound $round): void {
            // Both the stay and the encounter are tenant-scoped (BelongsToTenant), so a reference
            // to another tenant's row is invisible here and rejected — fail-closed.
            if (! empty($round->stay_id) && ! Stay::whereKey($round->stay_id)->exists()) {
                throw CrossTenantReferenceException::forAttribute('stay_id', (string) $round->stay_id);
            }
            if (! empty($round->encounter_id) && ! Encounter::whereKey($round->encounter_id)->exists()) {
                throw CrossTenantReferenceException::forAttribute('encounter_id', (string) $round->encounter_id);
            }
        });
    }

    public function stay(): BelongsTo
    {
        return $this->belongsTo(Stay::class);
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }
}
