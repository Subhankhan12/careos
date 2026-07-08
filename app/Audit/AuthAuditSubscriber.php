<?php

namespace App\Audit;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Events\Dispatcher;
use Modules\Audit\Services\AuditService;
use Modules\Platform\Models\User;

/**
 * Emits audit events for framework auth events (Fortify fires these). Lives in
 * the app layer (outside app/Listeners so it is not double-registered by
 * Laravel's event auto-discovery) and bridges the Platform user + Audit write
 * path — neither module depends on the other.
 */
class AuthAuditSubscriber
{
    public function __construct(private readonly AuditService $audit) {}

    public function handleLogin(Login $event): void
    {
        $this->audit->record([
            'action' => 'auth.login',
            'actor_type' => 'user',
            'actor_id' => (string) $event->user->getAuthIdentifier(),
            'tenant_id' => $event->user instanceof User ? $event->user->tenant_id : null,
        ]);
    }

    public function handleFailed(Failed $event): void
    {
        $this->audit->record([
            'action' => 'auth.login_failed',
            'actor_type' => 'service',
            'actor_id' => null,
            'tenant_id' => null,
            'context' => ['email' => $event->credentials['email'] ?? null],
        ]);
    }

    public function handleLogout(Logout $event): void
    {
        $user = $event->user;

        $this->audit->record([
            'action' => 'auth.logout',
            'actor_type' => 'user',
            'actor_id' => (string) $user->getAuthIdentifier(),
            'tenant_id' => $user instanceof User ? $user->tenant_id : null,
        ]);
    }

    public function handlePasswordReset(PasswordReset $event): void
    {
        $user = $event->user;

        $this->audit->record([
            'action' => 'auth.password_reset',
            'actor_type' => 'user',
            'actor_id' => (string) $user->getAuthIdentifier(),
            'tenant_id' => $user instanceof User ? $user->tenant_id : null,
        ]);
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'handleLogin',
            Failed::class => 'handleFailed',
            Logout::class => 'handleLogout',
            PasswordReset::class => 'handlePasswordReset',
        ];
    }
}
