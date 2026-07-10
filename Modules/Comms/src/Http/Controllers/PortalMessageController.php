<?php

namespace Modules\Comms\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Comms\Models\Message;
use Modules\Comms\Models\Thread;
use Modules\Comms\Services\ThreadService;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PortalAccount;

/**
 * Portal messages: the patient's OWN threads only (G.1). All access rules —
 * own-thread, participant, portal account, consent — live in ThreadService's
 * fail-closed patient path; reading is patient-scoped read-logged there.
 */
class PortalMessageController
{
    public function index(Request $request, ThreadService $threads): Response
    {
        $patient = $this->patient($request);
        $threadRows = $threads->threadsForPatient($patient);

        $activeThread = null;
        $activeThreadId = (string) $request->query('thread_id', '');

        if ($activeThreadId !== '') {
            $thread = Thread::query()->whereKey($activeThreadId)->firstOrFail();
            // Fail-closed patient access + patient-scoped read logging.
            $messages = $threads->messagesForPatient($thread, $patient);
            $threads->markPatientRead($thread, $patient);

            $activeThread = [
                'id' => $thread->id,
                'subject' => $thread->subject,
                'status' => $thread->status,
                'messages' => $messages->map(fn (Message $message): array => [
                    'id' => $message->id,
                    'author_type' => $message->author_type,
                    'body' => $message->body,
                    'sent_at' => $message->sent_at->toDateTimeString(),
                ])->all(),
            ];
        }

        return Inertia::render('Portal/Messages', [
            'threads' => $threadRows->map(fn (Thread $thread): array => [
                'id' => $thread->id,
                'subject' => $thread->subject,
                'status' => $thread->status,
                'last_message_at' => $thread->last_message_at?->toDateTimeString(),
                'unread' => $threads->patientUnreadCount($thread, $patient),
            ])->all(),
            'activeThread' => $activeThread,
            'actions' => [
                'storeUrl' => route('portal.messages.store'),
            ],
        ]);
    }

    public function store(Request $request, ThreadService $threads): RedirectResponse
    {
        $patient = $this->patient($request);

        $data = $request->validate([
            'thread_id' => ['required', 'string'],
            'body' => ['required', 'string', 'max:10000'],
        ]);

        $thread = Thread::query()->whereKey($data['thread_id'])->firstOrFail();

        // ThreadService enforces the fail-closed patient path and appends.
        $threads->postPatientMessage($thread, $patient, $data['body']);
        $threads->markPatientRead($thread->refresh(), $patient);

        return redirect()->route('portal.messages', ['thread_id' => $thread->id]);
    }

    private function patient(Request $request): Patient
    {
        $account = $request->user('patient');
        abort_unless($account instanceof PortalAccount, 401);

        return Patient::query()->whereKey($account->patient_id)->firstOrFail();
    }
}
