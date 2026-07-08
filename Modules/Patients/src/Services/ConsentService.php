<?php

namespace Modules\Patients\Services;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Audit\Services\AuditService;
use Modules\Patients\Models\ConsentTemplate;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PatientConsent;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

class ConsentService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly TenantContext $tenants,
    ) {}

    /**
     * @param  array<string, mixed>|string  $signature
     */
    public function grant(
        Patient $patient,
        string $templateKey,
        array|string $signature,
        User|int $capturedBy,
        ?Carbon $expiresAt = null,
    ): PatientConsent {
        return DB::transaction(function () use ($patient, $templateKey, $signature, $capturedBy, $expiresAt): PatientConsent {
            $template = ConsentTemplate::query()
                ->where('key', $templateKey)
                ->where('is_active', true)
                ->orderByDesc('version')
                ->lockForUpdate()
                ->first();

            if ($template === null) {
                throw (new ModelNotFoundException)->setModel(ConsentTemplate::class, [$templateKey]);
            }

            $capturer = $this->capturer($capturedBy);
            $signed = $this->signedPayload($signature, $patient, $template, $capturer);

            $consent = PatientConsent::create([
                'patient_id' => $patient->id,
                'template_id' => $template->id,
                'template_version' => $template->version,
                'template_key' => $template->key,
                'template_title' => $template->title,
                'template_body' => $template->body,
                'template_scope_keys' => $template->scope_keys,
                'status' => PatientConsent::STATUS_GRANTED,
                'granted_at' => Carbon::now(),
                'expires_at' => $expiresAt,
                'signature' => $signed,
                'captured_by' => $capturer->id,
            ]);

            $this->audit->record([
                'action' => 'consent.granted',
                'resource_type' => 'patient_consent',
                'resource_id' => $consent->id,
                'patient_id' => $patient->id,
                'context' => [
                    'template_key' => $template->key,
                    'template_version' => $template->version,
                    'scope_keys' => $template->scope_keys,
                    'signature_hash' => $signed['hash'],
                    'captured_by' => $capturer->id,
                    'expires_at' => $expiresAt?->toISOString(),
                ],
            ]);

            return $consent->refresh();
        });
    }

    public function withdraw(PatientConsent $patientConsent, string $reason): PatientConsent
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('A consent withdrawal reason is required.');
        }

        return DB::transaction(function () use ($patientConsent, $reason): PatientConsent {
            $patientConsent->forceFill([
                'status' => PatientConsent::STATUS_WITHDRAWN,
                'withdrawn_at' => Carbon::now(),
            ])->save();

            $this->audit->record([
                'action' => 'consent.withdrawn',
                'resource_type' => 'patient_consent',
                'resource_id' => $patientConsent->id,
                'patient_id' => $patientConsent->patient_id,
                'reason' => $reason,
                'context' => [
                    'template_key' => $patientConsent->template_key,
                    'template_version' => $patientConsent->template_version,
                    'scope_keys' => $patientConsent->template_scope_keys,
                ],
            ]);

            return $patientConsent->refresh();
        });
    }

    public function has(Patient $patient, string $scopeKey): bool
    {
        $now = Carbon::now();

        return PatientConsent::query()
            ->where('patient_id', $patient->id)
            ->where('status', PatientConsent::STATUS_GRANTED)
            ->where(function ($query) use ($now): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>=', $now);
            })
            ->get()
            ->contains(fn (PatientConsent $consent): bool => in_array($scopeKey, $consent->template_scope_keys, true));
    }

    private function capturer(User|int $capturedBy): User
    {
        $user = $capturedBy instanceof User ? $capturedBy : User::query()->find($capturedBy);
        $tenantId = $this->tenants->id();

        if ($user === null || $user->tenant_id !== $tenantId) {
            throw CrossTenantReferenceException::forAttribute('captured_by', (string) ($capturedBy instanceof User ? $capturedBy->id : $capturedBy));
        }

        return $user;
    }

    /**
     * @param  array<string, mixed>|string  $signature
     * @return array<string, mixed>
     */
    private function signedPayload(array|string $signature, Patient $patient, ConsentTemplate $template, User $capturedBy): array
    {
        $payload = is_array($signature)
            ? $signature
            : ['name' => $signature];

        $name = trim((string) ($payload['name'] ?? $payload['typed_name'] ?? ''));
        $method = trim((string) ($payload['method'] ?? 'typed'));

        if ($name === '') {
            throw new InvalidArgumentException('A typed signature name is required.');
        }

        if ($method === '') {
            $method = 'typed';
        }

        $signedAt = (string) ($payload['signed_at'] ?? Carbon::now()->toISOString());
        $normalized = [
            'name' => $name,
            'method' => $method,
            'signed_at' => $signedAt,
        ];
        $normalized['hash'] = hash('sha256', json_encode([
            'tenant_id' => $this->tenants->id(),
            'patient_id' => $patient->id,
            'template_id' => $template->id,
            'template_version' => $template->version,
            'template_body' => $template->body,
            'scope_keys' => $template->scope_keys,
            'captured_by' => $capturedBy->id,
            ...$normalized,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $normalized;
    }
}
