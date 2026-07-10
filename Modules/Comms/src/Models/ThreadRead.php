<?php

namespace Modules\Comms\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * Per-staff read marker for a thread. Unread counts are DERIVED from this
 * marker against the append-only message stream — never stored-and-drifting.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $thread_id
 * @property int $staff_user_id
 * @property string|null $last_read_message_id
 * @property Carbon $read_at
 */
class ThreadRead extends Model
{
    use BelongsToTenant, HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'thread_id',
        'staff_user_id',
        'last_read_message_id',
        'read_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }
}
