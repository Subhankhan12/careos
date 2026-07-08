<?php

namespace Modules\AiCore\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Modules\AiCore\Exceptions\AiInteractionImmutableException;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * Read-only view over the append-only AI interaction ledger.
 *
 * Writes happen through AiInteractionRecorder only. UPDATE/DELETE are blocked
 * here and by database triggers.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $feature
 * @property string $agent
 * @property string $provider
 * @property string $model
 * @property string $model_version
 * @property string $prompt_hash
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $cost_minor
 * @property array<string, mixed>|null $tool_calls
 * @property string|null $output_ref
 * @property string|null $approver_id
 * @property int $latency_ms
 * @property string $outcome
 * @property string $label
 * @property string|null $error_message
 * @property array<string, mixed>|null $metadata
 */
class AiInteraction extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'ai_interactions';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tool_calls' => 'array',
            'metadata' => 'array',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cost_minor' => 'integer',
            'latency_ms' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw AiInteractionImmutableException::make();
        });

        static::deleting(function (): void {
            throw AiInteractionImmutableException::make();
        });
    }
}
