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
use Modules\Patients\Notifications\PortalPasswordResetNotification;
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

        /*
         * TENANT BINDING. The context above came FROM the token, so this lookup can only ever
         * find an account inside the token's own tenant. A token pointing anywhere else resolves
         * to nothing — and is refused as an invalid invitation, the SAME refusal an unknown,
         * expired or consumed token gets, so a guest surface can answer all four identically
         * (PT.P6). It was previously a bare firstOrFail(), which 404d and thereby told a prober
         * that this particular token existed.
         */
        $account = PortalAccount::query()->whereKey($loginToken->portal_account_id)->lockForUpdate()->first();

        if (! $account instanceof PortalAccount) {
            throw ValidationException::withMessages(['token' => 'Invalid portal invitation.']);
        }

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

    /**
     * Read-only context for the invite landing page (PT.P6): the address the invitation was sent
     * to, the practice that sent it, and when the link stops working. `null` for a token that is
     * unknown, expired or already used — the caller renders ONE generic refusal for all of them,
     * so nothing here may distinguish the cases.
     *
     * A guest reaches this with no tenant context: the token is the tenant-bound secret, so the
     * lookup runs unscoped and the tenant is then taken FROM the token — never from the session.
     *
     * @return array{email: string, practiceName: string, expiresAt: string}|null
     */
    public function previewInvite(string $token): ?array
    {
        $loginToken = $this->redeemableToken($token, PortalLoginToken::PURPOSE_INVITE);

        if (! $loginToken instanceof PortalLoginToken) {
            return null;
        }

        $tenant = Tenant::query()->find($loginToken->tenant_id);

        if (! $tenant instanceof Tenant) {
            return null;
        }

        // The account is tenant-scoped, so it resolves inside the TOKEN's tenant. A token whose
        // account does not live in that tenant resolves to nothing and is refused like any other
        // dead token — the binding, not a message.
        $account = $this->inTenant($tenant, fn (): ?PortalAccount => PortalAccount::query()
            ->whereKey($loginToken->portal_account_id)
            ->first());

        // `email` is NOT NULL on portal_accounts — the invitation was sent to it — so the only
        // way there is nothing to show is no account, which is the tenant binding refusing.
        if (! $account instanceof PortalAccount) {
            return null;
        }

        return [
            'email' => $account->email,
            'practiceName' => $tenant->name,
            'expiresAt' => $loginToken->expires_at->toIso8601String(),
        ];
    }

    /**
     * PT.P7 — ask for a password-reset link.
     *
     * **This method tells the caller NOTHING.** It returns void and behaves identically whether the
     * address belongs to a live portal account, to nobody, to a patient who was never invited, or to
     * a disabled account. That is not politeness: a recovery form that answers differently is an
     * account-enumeration oracle, and it sits on a public URL (D-185).
     *
     * A token is issued only for an ACTIVE account. A disabled one gets nothing — recovery must not
     * be a way back into an account someone deliberately switched off.
     */
    public function requestPasswordReset(string $email): void
    {
        $email = strtolower(trim($email));

        // A guest arrives with no tenant, and the address is globally unique across portal accounts,
        // so the lookup runs unscoped and the tenant is then taken FROM the account.
        $account = $this->withoutTenantContext(fn () => $this->tenants->system(
            fn () => PortalAccount::query()->where('email', $email)->first()
        ));

        if (! $account instanceof PortalAccount || $account->status !== PortalAccount::STATUS_ACTIVE) {
            return;
        }

        $tenant = Tenant::query()->find($account->tenant_id);

        if (! $tenant instanceof Tenant) {
            return;
        }

        $this->inTenant($tenant, function () use ($account, $email): void {
            DB::transaction(function () use ($account, $email): void {
                /*
                 * SUPERSEDE the account's other live reset tokens. Only the newest link works, so a
                 * link sitting in an older mail — forwarded, cached, or read by whoever prompted the
                 * earlier request — is dead the moment a new one is asked for. `consumed_at` is the
                 * existing "this can never be redeemed again" marker; reusing it keeps ONE rule for
                 * what makes a token dead rather than inventing a second one.
                 *
                 * The INVITE flow deliberately keeps its own behaviour (PT.P6) — not this gate's to
                 * change.
                 */
                PortalLoginToken::query()
                    ->where('portal_account_id', $account->id)
                    ->where('purpose', PortalLoginToken::PURPOSE_PASSWORD_RESET)
                    ->whereNull('consumed_at')
                    ->update(['consumed_at' => Carbon::now()]);

                $plainToken = Str::random(64);
                $otp = (string) random_int(100000, 999999);

                // Only the HASH is stored. The raw token exists in this method and in the email.
                $loginToken = new PortalLoginToken([
                    'purpose' => PortalLoginToken::PURPOSE_PASSWORD_RESET,
                    'token_hash' => $this->hashToken($plainToken),
                    'otp_hash' => Hash::make($otp),
                    'expires_at' => Carbon::now()->addMinutes(30),
                ]);
                $loginToken->portal_account_id = $account->id;
                $loginToken->save();

                Notification::route('mail', $email)
                    ->notify(new PortalPasswordResetNotification($plainToken, $otp));

                $this->audit->record([
                    'action' => 'portal.password_reset_requested',
                    'resource_type' => 'portal_account',
                    'resource_id' => $account->id,
                    'patient_id' => $account->patient_id,
                    // The token id, never the token. The address is already on the account.
                    'context' => ['token_id' => $loginToken->id, 'expires_at' => $loginToken->expires_at->toISOString()],
                ]);
            });
        });
    }

    /**
     * Read-only context for the set-a-new-password page: the practice, and when the link dies.
     *
     * Deliberately NOT the email address. The invite page shows it because the invitation is
     * addressed to someone who may not yet know which address the practice holds; a reset is asked
     * for by the account holder, who typed the address themselves a minute ago. Less disclosure on a
     * public URL, for no lost function.
     *
     * @return array{practiceName: string, expiresAt: string}|null
     */
    public function previewPasswordReset(string $token): ?array
    {
        $loginToken = $this->redeemableToken($token, PortalLoginToken::PURPOSE_PASSWORD_RESET);

        if (! $loginToken instanceof PortalLoginToken) {
            return null;
        }

        $tenant = Tenant::query()->find($loginToken->tenant_id);

        if (! $tenant instanceof Tenant) {
            return null;
        }

        // Tenant-scoped: the account resolves inside the TOKEN's tenant, so a token pointing outside
        // it finds nothing and is refused exactly like a token that never existed.
        $account = $this->inTenant($tenant, fn (): ?PortalAccount => PortalAccount::query()
            ->whereKey($loginToken->portal_account_id)
            ->first());

        if (! $account instanceof PortalAccount || $account->status !== PortalAccount::STATUS_ACTIVE) {
            return null;
        }

        return [
            'practiceName' => $tenant->name,
            'expiresAt' => $loginToken->expires_at->toIso8601String(),
        ];
    }

    /**
     * Redeem a reset token: set the new password and consume the token. Single-use, purpose-scoped,
     * tenant-bound, and it changes EXACTLY ONE THING.
     *
     * **What a reset must not do**, and does not: it does not activate a disabled account, it does
     * not touch consent, and it does not sign anybody in. The patient goes to the sign-in page
     * afterwards and passes through {@see login()} — which re-checks `portal.access` — like anyone
     * else. A recovery flow that logged the user in would be a way around the consent gate PT.P5
     * built (D-183: the inner layer must still be the one that decides).
     *
     * @throws ValidationException
     */
    public function resetPassword(string $token, string $otp, string $password): PortalAccount
    {
        $loginToken = $this->redeemableToken($token, PortalLoginToken::PURPOSE_PASSWORD_RESET);

        if (! $loginToken instanceof PortalLoginToken) {
            throw ValidationException::withMessages(['token' => 'Invalid password reset link.']);
        }

        $tenant = Tenant::query()->findOrFail($loginToken->tenant_id);
        $this->tenants->set($tenant);

        if (! Hash::check($otp, $loginToken->otp_hash)) {
            throw ValidationException::withMessages(['otp' => 'Invalid portal code.']);
        }

        $account = PortalAccount::query()->whereKey($loginToken->portal_account_id)->lockForUpdate()->first();

        // No account inside the token's own tenant, or an account that is no longer active: the same
        // refusal a dead token gets, so the four cases stay indistinguishable on the guest surface.
        if (! $account instanceof PortalAccount || $account->status !== PortalAccount::STATUS_ACTIVE) {
            throw ValidationException::withMessages(['token' => 'Invalid password reset link.']);
        }

        return DB::transaction(function () use ($account, $loginToken, $password): PortalAccount {
            // The password is hash-cast on the model — the same path invite acceptance uses.
            $account->forceFill(['password' => $password])->save();

            $loginToken->forceFill(['consumed_at' => Carbon::now()])->save();

            $this->audit->record([
                'actor_type' => 'patient',
                'actor_id' => $account->id,
                'action' => 'portal.password_reset',
                'resource_type' => 'portal_account',
                'resource_id' => $account->id,
                'patient_id' => $account->patient_id,
                'context' => ['token_id' => $loginToken->id],
            ]);

            return $account->refresh();
        });
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
        $loginToken = $this->redeemableToken($token, PortalLoginToken::PURPOSE_INVITE);

        if (! $loginToken instanceof PortalLoginToken) {
            throw ValidationException::withMessages(['token' => 'Invalid portal invitation.']);
        }

        return $loginToken;
    }

    /**
     * The ONE definition of a redeemable token — unknown, WRONG PURPOSE, already consumed and
     * expired are all simply "not redeemable". Every landing page and every redemption path asks
     * this same question, so a page can never show something the POST would then refuse, nor
     * refuse something it would accept.
     *
     * The purpose is part of the question (PT.P7): an invite token is not a reset token, and
     * presenting one on the other's route is indistinguishable from presenting a token that never
     * existed.
     *
     * Unscoped by design: a guest arrives with no tenant, and the token carries its own.
     */
    private function redeemableToken(string $token, string $purpose): ?PortalLoginToken
    {
        $hash = $this->hashToken($token);

        return $this->withoutTenantContext(fn () => $this->tenants->system(fn () => PortalLoginToken::query()
            ->where('token_hash', $hash)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->where('expires_at', '>=', Carbon::now())
            ->first()));
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
     * Run a callback with the tenant context set to $tenant, restoring the previous one after.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function inTenant(Tenant $tenant, callable $callback): mixed
    {
        $previous = $this->tenants->current();
        $this->tenants->set($tenant);

        try {
            return $callback();
        } finally {
            $previous !== null ? $this->tenants->set($previous) : $this->tenants->forget();
        }
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
