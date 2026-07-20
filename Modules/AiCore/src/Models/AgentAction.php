<?php

namespace Modules\AiCore\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * Human approval queue for governed agent actions.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $interaction_id
 * @property string $feature
 * @property string $agent
 * @property string $tool_key
 * @property string $autonomy_level
 * @property string $status
 * @property string|null $proposed_by
 * @property string|null $reviewed_by
 * @property Carbon|null $approved_at
 * @property Carbon|null $rejected_at
 * @property Carbon|null $executed_at
 * @property string|null $rejection_reason
 * @property string $why
 * @property array<string, mixed> $input_payload
 * @property array<string, mixed>|null $proposed_output
 * @property array<string, mixed>|null $diff
 * @property array<string, mixed>|null $edited_payload
 * @property array<string, mixed>|null $result
 */
class AgentAction extends Model
{
    use BelongsToTenant, HasUlids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_EXECUTED = 'executed';

    public const STATUS_REJECTED = 'rejected';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'interaction_id',
        'feature',
        'agent',
        'tool_key',
        'autonomy_level',
        'status',
        'proposed_by',
        'reviewed_by',
        'approved_at',
        'rejected_at',
        'executed_at',
        'rejection_reason',
        'why',
        'input_payload',
        'proposed_output',
        'diff',
        'edited_payload',
        'result',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'input_payload' => 'array',
            'proposed_output' => 'array',
            'diff' => 'array',
            'edited_payload' => 'array',
            'result' => 'array',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'executed_at' => 'datetime',
        ];
    }
}
