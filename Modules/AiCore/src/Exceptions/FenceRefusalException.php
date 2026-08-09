<?php

namespace Modules\AiCore\Exceptions;

use Modules\AiCore\Services\ApprovalQueue;

/**
 * The ELECTRIC FENCE refused an action at approve time (e.g. a handed-off draft has nothing to
 * send). A subclass of {@see AiCoreException}, so every existing `catch (AiCoreException)` still
 * catches it — but a distinct type lets {@see ApprovalQueue::approve()}
 * RECORD the refusal as a countable `fence_refused` outcome before it propagates.
 *
 * This type does NOT change when or whether the fence fires; it only carries the fence's own
 * reason (its message) so the refusal can be recorded and surfaced honestly.
 */
class FenceRefusalException extends AiCoreException {}
