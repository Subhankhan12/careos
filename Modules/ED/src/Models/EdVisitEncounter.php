<?php

namespace Modules\ED\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Clinical\Models\Encounter;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;

/**
 * The ED-SIDE link tying an {@see EdVisit} to a Clinical {@see Encounter} created during it (ED.G4 — the
 * `WardRound` / `SurgicalCaseEncounter` precedent). Clinical's `Encounter` is REUSED UNMODIFIED (its schema +
 * one-open-per-practitioner invariant untouched); this link keeps the association ED-side. ED notes/vitals/
 * orders hang off the Encounter through the existing Clinical services.
 *
 * ED may use Clinical (allowed by the module boundary) — the same dependency inpatient/surgery/dental use for
 * encounters/documents.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $ed_visit_id
 * @property string $encounter_id
 * @property-read EdVisit|null $edVisit
 * @property-read Encounter|null $encounter
 */
class EdVisitEncounter extends Model
{
    use BelongsToTenant, HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'ed_visit_id',
        'encounter_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (EdVisitEncounter $link): void {
            // Both the visit and the encounter are tenant-scoped (BelongsToTenant), so a reference to another
            // tenant's row is invisible here and rejected — fail-closed.
            if (! empty($link->ed_visit_id) && ! EdVisit::whereKey($link->ed_visit_id)->exists()) {
                throw CrossTenantReferenceException::forAttribute('ed_visit_id', (string) $link->ed_visit_id);
            }
            if (! empty($link->encounter_id) && ! Encounter::whereKey($link->encounter_id)->exists()) {
                throw CrossTenantReferenceException::forAttribute('encounter_id', (string) $link->encounter_id);
            }
        });
    }

    public function edVisit(): BelongsTo
    {
        return $this->belongsTo(EdVisit::class);
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }
}
