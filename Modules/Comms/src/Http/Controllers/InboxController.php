<?php

namespace Modules\Comms\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Comms\Contracts\InboxDraftProvider;
use Modules\Comms\Models\Message;
use Modules\Comms\Models\Thread;
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
    public function __construct(private readonly InboxDraftProvider $drafts) {}

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
        ];

        $threadRows = Thread::query()
            ->when($filters['type'], fn ($query, $type) => $query->where('type', $type))
            ->where('status', $filters['status'])
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

            $activeThread = [
                ...$this->threadSummary($thread, $threads, $user),
                'messages' => $messages->map(fn (Message $message): array => [
                    'id' => $message->id,
                    'author_type' => $message->author_type,
                    'body' => $message->body,
                    'ai_assisted' => $message->ai_assisted,
                    'sent_at' => $message->sent_at->toDateTimeString(),
                ])->all(),
                'clinician_attention_at' => $thread->clinician_attention_at?->toDateTimeString(),
                'clinician_attention_reason' => $thread->clinician_attention_reason,
                'aiDraft' => $this->drafts->pendingDraftFor($thread),
            ];
        }

        return Inertia::render('Comms/Inbox', [
            'filters' => $filters,
            'threads' => $threadRows
                ->map(fn (Thread $thread): array => $this->threadSummary($thread, $threads, $user))
                ->all(),
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
