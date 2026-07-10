<?php

namespace Modules\Comms\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Comms\Models\Thread;
use Modules\Comms\Services\ThreadService;
use Modules\Platform\Models\User;

/**
 * Inbox actions. Every rule is enforced in ThreadService (RBAC, tenancy,
 * open-thread, append-only); this controller validates shape and redirects.
 */
class InboxActionController
{
    public function reply(Request $request, ThreadService $threads): RedirectResponse
    {
        $data = $request->validate([
            'thread_id' => ['required', 'string'],
            'body' => ['required', 'string', 'max:10000'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $thread = Thread::query()->whereKey($data['thread_id'])->firstOrFail();

        $threads->postStaffMessage($thread, $user, $data['body']);
        $threads->markRead($thread->refresh(), $user);

        return redirect()->route('comms.inbox', ['thread_id' => $thread->id]);
    }

    public function status(Request $request, ThreadService $threads): RedirectResponse
    {
        $data = $request->validate([
            'thread_id' => ['required', 'string'],
            'action' => ['required', 'in:close,reopen'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $thread = Thread::query()->whereKey($data['thread_id'])->firstOrFail();

        $data['action'] === 'close'
            ? $threads->close($thread, $user)
            : $threads->reopen($thread, $user);

        return redirect()->route('comms.inbox', ['thread_id' => $thread->id]);
    }

    public function assign(Request $request, ThreadService $threads): RedirectResponse
    {
        $data = $request->validate([
            'thread_id' => ['required', 'string'],
            'assigned_to' => ['nullable', 'integer'],
            'assign_self' => ['sometimes', 'boolean'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $thread = Thread::query()->whereKey($data['thread_id'])->firstOrFail();

        $assignee = null;
        if (($data['assign_self'] ?? false) === true) {
            $assignee = $user;
        } elseif (isset($data['assigned_to'])) {
            $assignee = User::query()->findOrFail((int) $data['assigned_to']);
        }

        $threads->assign($thread, $assignee, $user);

        return redirect()->route('comms.inbox', ['thread_id' => $thread->id]);
    }
}
