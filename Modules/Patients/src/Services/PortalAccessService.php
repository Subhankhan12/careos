<?php

namespace Modules\Patients\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Audit\Services\AuditService;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PortalAccount;
use Modules\Patients\Models\PortalLoginToken;
use Modules\Patients\Notifications\PortalInviteNotification;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Services\TenantContext;

class PortalAccessService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly ConsentService $consents,
        private readonly TenantContext $tenants,
    ) {}

    /**
     * @throws AuthorizationException
     */
    public function invite(Patient $patient, string $email): PortalInvite
    {
        $email = strtolower(trim($email));

        if (! $this->consents->has($patient, 'portal.access')) {
            throw new AuthorizationException('Portal access consent is required.');
        }

        return DB::transaction(function () use ($patient, $email): PortalInvite {
            $account = PortalAccount::query()->firstOrNew(['patient_id' => $patient->id]);
            $account->forceFill([
                'email' => $email,
                'status' => $account->status === PortalAccount::STATUS_ACTIVE
                    ? PortalAccount::STATUS_ACTIVE
                    : PortalAccount::STATUS_INVITED,
                'invited_at' => Carbon::now(),
            ])->save();

            $plainToken = Str::random(64);
            $otp = (string) random_int(100000, 999999);
            $loginToken = new PortalLoginToken([
                'purpose' => PortalLoginToken::PURPOSE_INVITE,
                'token_hash' => $this->hashToken($plainToken),
                'otp_hash' => Hash::make($otp),
                'expires_at' => Carbon::now()->addMinutes(30),
            ]);
            $loginToken->portal_account_id = $account->id;
            $loginToken->save();

            Notification::route('mail', $email)
                ->notify(new PortalInviteNotification($plainToken, $otp));

            $this->audit->record([
                'action' => 'portal.invited',
                'resource_type' => 'portal_account',
                'resource_id' => $account->id,
                'patient_id' => $patient->id,
                'context' => [
                    'email' => $email,
                    'token_id' => $loginToken->id,
                    'expires_at' => $loginToken->expires_at->toISOString(),
                ],
            ]);

            return new PortalInvite($account->refresh(), $loginToken->refresh(), $plainToken, $otp);
        });
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function acceptInvite(string $token, string $otp, string $password): PortalAccount
    {
        $loginToken = $this->tokenForInvite($token);
        $tenant = Tenant::query()->findOrFail($loginToken->tenant_id);
        $this->tenants->set($tenant);

        if (! Hash::check($otp, $loginToken->otp_hash)) {
            throw ValidationException::withMessages(['otp' => 'Invalid portal code.']);
        }

        $account = PortalAccount::query()->whereKey($loginToken->portal_account_id)->lockForUpdate()->firstOrFail();

        if (! $this->consents->has($this->patientFor($account), 'portal.access')) {
            throw new AuthorizationException('Portal access consent is required.');
        }

        return DB::transaction(function () use ($account, $loginToken, $password): PortalAccount {
            $firstActivation = $account->activated_at === null;

            $account->forceFill([
                'password' => $password,
                'status' => PortalAccount::STATUS_ACTIVE,
                'activated_at' => $account->activated_at ?? Carbon::now(),
                'last_login_at' => Carbon::now(),
            ])->save();

            $loginToken->forceFill(['consumed_at' => Carbon::now()])->save();

            $this->loginGuard($account);

            if ($firstActivation) {
                $this->audit->record([
                    'actor_type' => 'patient',
                    'actor_id' => $account->id,
                    'action' => 'portal.first_login',
                    'resource_type' => 'portal_account',
                    'resource_id' => $account->id,
                    'patient_id' => $account->patient_id,
                ]);
            }

            $this->auditPortalLogin($account, 'magic_link_otp');

            return $account->refresh();
        });
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function login(string $email, string $password): PortalAccount
    {
        $email = strtolower(trim($email));
        $account = $this->withoutTenantContext(fn () => $this->tenants->system(
            fn () => PortalAccount::query()->where('email', $email)->first()
        ));

        if (! $account instanceof PortalAccount || $account->password === null || ! Hash::check($password, $account->password)) {
            throw ValidationException::withMessages(['email' => 'Invalid portal credentials.']);
        }

        $tenant = Tenant::query()->findOrFail($account->tenant_id);
        $this->tenants->set($tenant);
        $account = PortalAccount::query()->whereKey($account->id)->firstOrFail();

        if ($account->status !== PortalAccount::STATUS_ACTIVE) {
            throw ValidationException::withMessages(['email' => 'Invalid portal credentials.']);
        }

        if (! $this->consents->has($this->patientFor($account), 'portal.access')) {
            throw new AuthorizationException('Portal access consent is required.');
        }

        $account->forceFill(['last_login_at' => Carbon::now()])->save();
        $this->loginGuard($account);
        $this->auditPortalLogin($account, 'password');

        return $account->refresh();
    }

    private function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * @throws ValidationException
     */
    private function tokenForInvite(string $token): PortalLoginToken
    {
        $hash = $this->hashToken($token);

        $loginToken = $this->withoutTenantContext(fn () => $this->tenants->system(fn () => PortalLoginToken::query()
            ->where('token_hash', $hash)
            ->where('purpose', PortalLoginToken::PURPOSE_INVITE)
            ->whereNull('consumed_at')
            ->where('expires_at', '>=', Carbon::now())
            ->first()));

        if (! $loginToken instanceof PortalLoginToken) {
            throw ValidationException::withMessages(['token' => 'Invalid portal invitation.']);
        }

        return $loginToken;
    }

    private function loginGuard(PortalAccount $account): void
    {
        Auth::guard('patient')->login($account);

        if (request()->hasSession()) {
            request()->session()->put('portal_tenant_id', $account->tenant_id);
        }
    }

    private function auditPortalLogin(PortalAccount $account, string $method): void
    {
        $this->audit->record([
            'actor_type' => 'patient',
            'actor_id' => $account->id,
            'action' => 'portal.login',
            'resource_type' => 'portal_account',
            'resource_id' => $account->id,
            'patient_id' => $account->patient_id,
            'context' => ['method' => $method],
        ]);
    }

    private function patientFor(PortalAccount $account): Patient
    {
        return Patient::query()->whereKey($account->patient_id)->firstOrFail();
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function withoutTenantContext(callable $callback): mixed
    {
        $previous = $this->tenants->current();
        $this->tenants->forget();

        try {
            return $callback();
        } finally {
            if ($previous !== null) {
                $this->tenants->set($previous);
            }
        }
    }
}
