<?php

namespace Modules\FrontDesk\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Models\Branch;

/**
 * A tenant-owned kiosk device provisioned to ONE branch. Its token authorizes
 * only the self check-in flow — never a login and never any read outside
 * resolve + check-in.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $branch_id
 * @property string $name
 * @property string $token_hash
 * @property bool $active
 * @property Carbon|null $last_used_at
 * @property string|null $created_by
 */
class KioskDevice extends Model
{
    use BelongsToTenant, HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'branch_id',
        'name',
        'token_hash',
        'active',
        'last_used_at',
        'created_by',
    ];

    protected $attributes = [
        'active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
