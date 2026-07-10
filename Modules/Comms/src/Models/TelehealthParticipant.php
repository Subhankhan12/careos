<?php

namespace Modules\Comms\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * Append-only join/leave proof row. A leave fills left_at exactly once; every
 * other change and any delete is forbidden at model and DB-trigger levels.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $session_id
 * @property string $participant_type
 * @property string $participant_id
 * @property Carbon $joined_at
 * @property Carbon|null $left_at
 */
class TelehealthParticipant extends Model
{
    use BelongsToTenant, HasUlids;

    public const TYPE_STAFF = 'staff';

    public const TYPE_PATIENT = 'patient';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'session_id',
        'participant_type',
        'participant_id',
        'joined_at',
        'left_at',
    ];

    protected static function booted(): void
    {
        static::updating(function (TelehealthParticipant $participant): void {
            $onlyLeftAtSetOnce = array_keys($participant->getDirty()) === ['left_at']
                && $participant->getOriginal('left_at') === null
                && $participant->left_at !== null;

            if (! $onlyLeftAtSetOnce) {
                throw new LogicException('telehealth_participants are append-only: only left_at may be set, once.');
            }
        });

        static::deleting(function (): void {
            throw new LogicException('telehealth_participants are append-only: they cannot be deleted.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(TelehealthSession::class, 'session_id');
    }
}
