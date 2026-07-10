<?php

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Modules\Billing\Exceptions\TariffVersionOverlapException;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Services\SettingsService;

/**
 * Effective-dated catalog of billable items for one tenant.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $key
 * @property string $name
 * @property int $version
 * @property string $currency
 * @property Carbon $valid_from
 * @property Carbon|null $valid_to
 * @property string $status
 * @property array<string, mixed>|null $rules
 */
class TariffCatalog extends Model
{
    use BelongsToTenant, HasUlids;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUPERSEDED = 'superseded';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'key',
        'name',
        'version',
        'currency',
        'valid_from',
        'valid_to',
        'status',
        'rules',
    ];

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
    ];

    protected static function booted(): void
    {
        static::creating(function (TariffCatalog $catalog): void {
            $currency = $catalog->getAttribute('currency');

            if (! $catalog->isDirty('currency') || $currency === null || $currency === '') {
                $catalog->currency = (string) app(SettingsService::class)->get('currency', 'EUR');
            }
        });

        static::saving(function (TariffCatalog $catalog): void {
            $catalog->assertValidRange();
            $catalog->assertNoOverlap();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'rules' => 'array',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(TariffItem::class);
    }

    private function assertValidRange(): void
    {
        if ($this->valid_to !== null && $this->catalogDate($this->valid_to)->lt($this->catalogDate($this->valid_from))) {
            throw new InvalidArgumentException('Tariff catalog valid_to must be on or after valid_from.');
        }
    }

    private function assertNoOverlap(): void
    {
        $validFrom = $this->catalogDate($this->valid_from)->toDateString();
        $validTo = $this->valid_to === null ? null : $this->catalogDate($this->valid_to)->toDateString();

        $query = self::query()
            ->where('key', $this->key)
            ->where(function ($query) use ($validFrom): void {
                $query->whereNull('valid_to')
                    ->orWhereDate('valid_to', '>=', $validFrom);
            });

        if ($validTo !== null) {
            $query->whereDate('valid_from', '<=', $validTo);
        }

        if ($this->exists) {
            $query->whereKeyNot($this->getKey());
        }

        if ($query->exists()) {
            throw TariffVersionOverlapException::forCatalog($this->key, $validFrom, $validTo);
        }
    }

    private function catalogDate(mixed $value): Carbon
    {
        return $value instanceof Carbon ? $value->copy()->startOfDay() : Carbon::parse((string) $value)->startOfDay();
    }
}
