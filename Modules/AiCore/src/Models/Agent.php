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
 * @property string $name
 * @property string $autonomy_level
 * @property string $status
 * @property list<string> $tool_keys
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
        'name',
        'autonomy_level',
        'status',
        'tool_keys',
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
        ];
    }

    /** Whether this agent is allowed to call the given tool key (status + whitelist — narrows only). */
    public function mayCall(string $toolKey): bool
    {
        return $this->status === self::STATUS_ACTIVE && in_array($toolKey, $this->tool_keys ?? [], true);
    }
}
