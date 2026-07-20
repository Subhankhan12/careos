<?php

namespace Modules\Dental\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * The light tooth link: ties a billing `charge` (all economics live there) to the
 * odontogram tooth/surface a tooth-scoped dental procedure was done on. Stores no money.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $charge_id
 * @property string $dental_procedure_id
 * @property string|null $tooth
 * @property string|null $surface
 */
class DentalProcedureCharge extends Model
{
    use BelongsToTenant, HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'charge_id',
        'dental_procedure_id',
        'tooth',
        'surface',
    ];
}
