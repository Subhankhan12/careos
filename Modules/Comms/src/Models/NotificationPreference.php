<?php

namespace Modules\Comms\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * Tenant-owned per-event EMAIL notification preference (SETTINGS.P5). One row per event the admin
 * has changed; absence means the default (email ON). Consulted by {@see NotificationService} for
 * non-legal email events — legal notices are never suppressible.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $event_key
 * @property bool $email_enabled
 */
class NotificationPreference extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = [
        'event_key',
        'email_enabled',
    ];

    protected function casts(): array
    {
        return [
            'email_enabled' => 'boolean',
        ];
    }
}
