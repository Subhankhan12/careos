<?php

namespace Modules\Comms\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Comms\Contracts\InboxDraftProvider;
use Modules\Comms\Models\Message;
use Modules\Comms\Models\Thread;
use Modules\Comms\Services\InboxPatientContextReader;
use Modules\Comms\Services\ThreadService;
use Modules\Patients\Models\Patient;
use Modules\Platform\Models\User;

/**
 * The unified inbox. All rules live server-side (P0D.GU): RBAC here, access
 * and read-logging in ThreadService; the Vue page only renders props and
 * dispatches actions.
 */
class InboxController
{
    public function __construct(
        private readonly InboxDraftProvider $drafts,
        private readonly InboxPatientContextReader $context,
    ) {}

    public function __invoke(Request $request, ThreadService $threads): Response
    {
        Gate::authorize('comms.manage');

        /** @var User $user */
        $user = $request->user();

        $filters = [
            'type' => in_array($request->query('type'), [Thread::TYPE_PATIENT, Thread::TYPE_INTERNAL], true)
                ? $request->query('type')
                : null,
            'status' => in_array($request->query('status'), [Thread::STATUS_OPEN, Thread::STATUS_CLOSED], true)
                ? $request->query('status')
                : Thread::STATUS_OPEN,
            'scope' => $request->query('scope') === 'mine' ? 'mine' : 'all',
            // COMMS.P1 — "still needs a human". NOT the raw flag: the model's conjunction, which is
            // the same one the governance dashboard counts (GOV.P2).
            'needsHuman' => $request->query('needs_human') === '1',
        ];

        $threadRows = Thread::query()
            ->when($filters['type'], fn ($query, $type) => $query->where('type', $type))
            ->when(
                $filters['needsHuman'],
                // The conjunction carries `status = open` itself, so the status pill does not also
                // apply — a closed thread is BY DEFINITION no longer waiting on anyone.
                fn ($query) => $query->waitingForClinician(),
                fn ($query) => $query->where('status', $filters['status']),
            )
            ->when($filters['scope'] === 'mine', fn ($query) => $query->where('assigned_to', $user->id))
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        $activeThread = null;
        $activeThreadId = (string) $request->query('thread_id', '');

        if ($activeThreadId !== '') {
            $thread = Thread::query()->whereKey($activeThreadId)->firstOrFail();
            // messagesForStaff read-logs patient threads (patient data).
            $messages = $threads->messagesForStaff($thread, $user);
            $threads->markRead($thread, $user);

            $patient = $thread->patient_id !== null
                ? Patient::query()->find($thread->patient_id)
                : null;

            // The human who actually SENT each message, resolved in one query rather than per row.
            $senders = User::query()
                ->whereIn('id', $messages->pluck('author_staff_user_id')->filter()->unique()->all())
                ->pluck('name', 'id');

            $activeThread = [
                ...$this->threadSummary($thread, $threads, $user),
                'messages' => $messages->map(fn (Message $message): array => [
                    'id' => $message->id,
                    'author_type' => $message->author_type,
                    'body' => $message->body,
                    'ai_assisted' => $message->ai_assisted,
                    /*
                     * PROVENANCE, as recorded. `ai_assisted` says a draft was involved; this names
                     * the person whose explicit Send posted it. `DraftReplyTool::execute()` passes
                     * the HUMAN as the actor, so `author_staff_user_id` is genuinely the sender and
                     * not the agent — the two facts together are the whole claim the page makes.
                     * Nothing is inferred: a message with no staff author simply has no sender name.
                     */
                    'sender' => $message->author_staff_user_id !== null
                        ? ($senders[$message->author_staff_user_id] ?? null)
                        : null,
                    'sent_at' => $message->sent_at->toDateTimeString(),
                ])->all(),
                'clinician_attention_at' => $thread->clinician_attention_at?->toDateTimeString(),
                'clinician_attention_reason' => $thread->clinician_attention_reason,
                'aiDraft' => $this->drafts->pendingDraftFor($thread),
                /*
                 * The context pane. Patient threads only — an internal thread has no patient, and
                 * the reader is never called without one. Every element inside is permission-scoped
                 * fail-closed by the reader itself, not by this controller and not by the page.
                 */
                'context' => $patient instanceof Patient
                    ? $this->context->for($patient, $user)
                    : null,
            ];
        }

        return Inertia::render('Comms/Inbox', [
            'filters' => $filters,
            'threads' => $threadRows
                ->map(fn (Thread $thread): array => $this->threadSummary($thread, $threads, $user))
                ->all(),
            // Plain counts of rows that exist — no computed value, no judgment (D-166/D-174).
            // Counted where the record lives, never derived from the capped list above.
            'counts' => [
                'open' => Thread::query()->where('status', Thread::STATUS_OPEN)->count(),
                'needsHuman' => Thread::query()->waitingForClinician()->count(),
            ],
            'activeThread' => $activeThread,
            'staff' => User::query()->orderBy('name')->get(['id', 'name'])->all(),
            'actions' => [
                'replyUrl' => route('comms.inbox.reply'),
                'statusUrl' => route('comms.inbox.status'),
                'assignUrl' => route('comms.inbox.assign'),
                'aiDraftUrl' => route('comms.inbox.ai-draft'),
                'sendDraftUrl' => route('comms.inbox.send-draft'),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function threadSummary(Thread $thread, ThreadService $threads, User $user): array
    {
        $patient = $thread->patient_id !== null
            ? Patient::query()->find($thread->patient_id)
            : null;

        return [
            'id' => $thread->id,
            'subject' => $thread->subject,
            'type' => $thread->type,
            'status' => $thread->status,
            'patient' => $patient !== null ? trim($patient->first_name.' '.$patient->last_name) : null,
            'assigned_to' => $thread->assigned_to,
            'last_message_at' => $thread->last_message_at?->toDateTimeString(),
            'unread' => $threads->unreadCount($thread, $user),
        ];
    }
}
