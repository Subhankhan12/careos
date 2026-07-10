<?php

namespace Modules\Comms\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * Tenant-owned APPEND-ONLY delivery record: written once, when the delivery
 * attempt (or skip decision) happens, with its final status and a SNAPSHOT of
 * the rendered content. History is never re-rendered or rewritten.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $template_key
 * @property int $template_version
 * @property string $channel
 * @property string $category
 * @property string $recipient_type
 * @property string $recipient_id
 * @property string|null $patient_id
 * @property string|null $rendered_subject
 * @property string $rendered_body
 * @property string $status
 * @property string|null $skipped_reason
 * @property string $dedupe_key
 * @property Carbon|null $sent_at
 * @property string|null $error
 */
class NotificationDelivery extends Model
{
    use BelongsToTenant, HasUlids;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    public const RECIPIENT_PATIENT = 'patient';

    public const RECIPIENT_STAFF = 'staff';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'template_key',
        'template_version',
        'channel',
        'category',
        'recipient_type',
        'recipient_id',
        'patient_id',
        'rendered_subject',
        'rendered_body',
        'status',
        'skipped_reason',
        'dedupe_key',
        'sent_at',
        'error',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('notification_deliveries are append-only: they cannot be updated.');
        });
        static::deleting(function (): void {
            throw new LogicException('notification_deliveries are append-only: they cannot be deleted.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'template_version' => 'integer',
            'sent_at' => 'datetime',
        ];
    }
}
