<?php

namespace Modules\AiCore\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Modules\AiCore\Services\AgentResolver;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * A per-tenant governed agent (AGENT.P1) — a CONTAINER for configuration, never a source of
 * authority. It maps to a real code-class agent (`key`), holds a CONFIGURED autonomy level, a
 * status, and a whitelist of the tools it MAY call. These only NARROW: the effective autonomy for
 * any (agent, tool) is MIN(configured, tool ceiling, role ceiling), resolved server-side by
 * {@see AgentResolver}. Whitelisting a tool never grants it past its
 * ceiling; a non-whitelisted or paused agent cannot act.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $key
 * @property string|null $kind
 * @property string $name
 * @property string $autonomy_level
 * @property string $status
 * @property list<string> $tool_keys
 * @property int|null $max_drafts_per_hour
 * @property int|null $quiet_hours_start
 * @property int|null $quiet_hours_end
 */
class Agent extends Model
{
    use BelongsToTenant, HasUlids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'key',
        'kind',
        'name',
        'autonomy_level',
        'status',
        'tool_keys',
        'max_drafts_per_hour',
        'quiet_hours_start',
        'quiet_hours_end',
    ];

    protected $attributes = [
        'autonomy_level' => 'suggest',
        'status' => self::STATUS_ACTIVE,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tool_keys' => 'array',
            'max_drafts_per_hour' => 'integer',
            'quiet_hours_start' => 'integer',
            'quiet_hours_end' => 'integer',
        ];
    }

    /**
     * The canonical capability this agent is an instance of (an AgentRegistry key). The remit,
     * ceiling and ledger attribution derive from the KIND; falls back to the unique `key` for the
     * seeded canonical agents (where kind === key).
     */
    public function kind(): string
    {
        return $this->kind ?? $this->key;
    }

    /** Whether this agent is allowed to call the given tool key (status + whitelist — narrows only). */
    public function mayCall(string $toolKey): bool
    {
        return $this->status === self::STATUS_ACTIVE && in_array($toolKey, $this->tool_keys ?? [], true);
    }

    /**
     * Whether the given hour-of-day (0–23) falls inside this agent's quiet-hours window. Handles a
     * window that wraps past midnight (start > end). No window configured → never quiet.
     */
    public function isQuietHour(int $hour): bool
    {
        $start = $this->quiet_hours_start;
        $end = $this->quiet_hours_end;

        if ($start === null || $end === null || $start === $end) {
            return false;
        }

        return $start < $end
            ? ($hour >= $start && $hour < $end)          // same-day window
            : ($hour >= $start || $hour < $end);          // wraps past midnight
    }
}
