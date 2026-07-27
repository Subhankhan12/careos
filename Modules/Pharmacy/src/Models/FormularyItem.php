<?php

namespace Modules\Pharmacy\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Billing\Models\TariffItem;
use Modules\Pharmacy\Contracts\MedicationSafetyProvider;
use Modules\Pharmacy\Exceptions\FormularyException;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * A tenant-authored FORMULARY item (PHARMACY.G1) — one medication on the tenant's OWN list. The catalog
 * discipline of `TariffItem` / `OrderableItem` / `DentalProcedure`: tenant-authored, NO licensed drug data
 * bundled. `code` is the tenant's own code.
 *
 * ELECTRIC FENCE: a plain record — name / form / strength — with NO computed-safety field (no
 * interaction/dose/contraindication/duplicate flag, no severity/score/risk). Medication-safety judgment
 * lives ONLY behind the {@see MedicationSafetyProvider} certified-partner seam,
 * never on this row.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property string|null $form
 * @property string|null $strength
 * @property string|null $tariff_item_id
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read TariffItem|null $tariffItem
 */
class FormularyItem extends Model
{
    use BelongsToTenant, HasUlids;

    // A plain, closed set of dosage forms the tenant selects — an operational type, never a judgment.
    public const FORM_TABLET = 'tablet';

    public const FORM_CAPSULE = 'capsule';

    public const FORM_LIQUID = 'liquid';

    public const FORM_INJECTION = 'injection';

    public const FORM_TOPICAL = 'topical';

    public const FORM_OTHER = 'other';

    public const FORMS = [
        self::FORM_TABLET, self::FORM_CAPSULE, self::FORM_LIQUID,
        self::FORM_INJECTION, self::FORM_TOPICAL, self::FORM_OTHER,
    ];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'code',
        'name',
        'form',
        'strength',
        'tariff_item_id',
        'active',
    ];

    protected $attributes = [
        'active' => true,
    ];

    protected static function booted(): void
    {
        static::saving(function (FormularyItem $item): void {
            if (trim((string) $item->code) === '') {
                throw FormularyException::codeRequired();
            }
            if (trim((string) $item->name) === '') {
                throw FormularyException::nameRequired();
            }
            if ($item->form !== null && ! in_array($item->form, self::FORMS, true)) {
                throw FormularyException::invalidForm((string) $item->form);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    /**
     * The Billing tariff item that prices this med (PHARMACY.G5) — tenant-authored pricing in the existing
     * tariff store, NOT duplicated here. Null until priced. Pharmacy MAY use Billing (arch boundary).
     */
    public function tariffItem(): BelongsTo
    {
        return $this->belongsTo(TariffItem::class);
    }

    public function isPriced(): bool
    {
        return $this->tariff_item_id !== null;
    }

    protected function auditResourceType(): string
    {
        return 'formulary_item';
    }
}
