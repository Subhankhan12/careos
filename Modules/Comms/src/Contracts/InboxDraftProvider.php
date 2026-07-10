<?php

namespace Modules\Comms\Contracts;

use Modules\Comms\Models\Thread;

/**
 * Supplies the pending AI reply draft for a thread, if any. Implemented in the
 * app layer (D-017) so Comms never depends on AiCore.
 */
interface InboxDraftProvider
{
    /**
     * @return array{action_id: string, body: string, lines: list<array<string, mixed>>}|null
     */
    public function pendingDraftFor(Thread $thread): ?array;
}
