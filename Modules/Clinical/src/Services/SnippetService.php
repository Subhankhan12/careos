<?php

namespace Modules\Clinical\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Modules\Clinical\Events\ClinicalRecordChanged;
use Modules\Clinical\Models\TextSnippet;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

/**
 * Clinical dot-phrases / quick-text macros. Pure internal text expansion — NO
 * clinical interpretation, NO AI. The placeholder whitelist below is the ONLY
 * substitution ever performed, which makes it structurally impossible to inject
 * a diagnosis, medication, allergy, vital, or any clinical field: expand() only
 * ever iterates these fixed keys, never the caller's arbitrary context keys.
 */
class SnippetService
{
    /**
     * The FIXED, documented whitelist of safe NON-clinical placeholders. Nothing
     * outside this list is ever substituted.
     */
    public const PLACEHOLDERS = [
        'date',
        'patient_first_name',
        'patient_dob',
        'clinician_name',
        'branch_name',
    ];

    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * The snippet whose trigger matches for this clinician. PERSONAL wins over
     * SHARED when both exist (documented precedence). Tenant-scoped.
     */
    public function resolveFor(StaffProfile $staff, string $trigger): ?TextSnippet
    {
        $trigger = $this->normalizeTrigger($trigger);

        $personal = TextSnippet::query()
            ->where('scope', TextSnippet::SCOPE_PERSONAL)
            ->where('owner_staff_id', $staff->id)
            ->where('trigger', $trigger)
            ->where('active', true)
            ->first();

        if ($personal instanceof TextSnippet) {
            return $personal;
        }

        return TextSnippet::query()
            ->where('scope', TextSnippet::SCOPE_SHARED)
            ->where('trigger', $trigger)
            ->where('active', true)
            ->first();
    }

    /**
     * The clinician's available snippets: their own active personal snippets plus
     * ALL active shared snippets (never another clinician's personal).
     *
     * @return Collection<int, TextSnippet>
     */
    public function list(?StaffProfile $staff): Collection
    {
        return TextSnippet::query()
            ->where('active', true)
            ->where(function ($query) use ($staff): void {
                $query->where('scope', TextSnippet::SCOPE_SHARED);

                if ($staff !== null) {
                    $query->orWhere(function ($personal) use ($staff): void {
                        $personal->where('scope', TextSnippet::SCOPE_PERSONAL)
                            ->where('owner_staff_id', $staff->id);
                    });
                }
            })
            ->orderBy('trigger')
            ->get();
    }

    /**
     * Render the body, substituting ONLY whitelisted NON-clinical placeholders
     * that are present in $context. Unknown placeholders are left LITERAL — never
     * guessed, never sourced from clinical data.
     *
     * @param  array<string, mixed>  $context
     */
    public function expand(TextSnippet $snippet, array $context): string
    {
        $body = $snippet->body;

        foreach (self::PLACEHOLDERS as $key) {
            if (! array_key_exists($key, $context)) {
                continue; // present but unknown-value -> leave the token literal
            }

            $body = (string) preg_replace(
                '/\{\{\s*'.preg_quote($key, '/').'\s*\}\}/',
                (string) $context[$key],
                $body,
            );
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $actor, array $data): TextSnippet
    {
        $scope = (string) ($data['scope'] ?? '');
        if (! in_array($scope, TextSnippet::SCOPES, true)) {
            throw new InvalidArgumentException('Unknown snippet scope.');
        }

        $trigger = $this->normalizeTrigger((string) ($data['trigger'] ?? ''));
        $title = trim((string) ($data['title'] ?? ''));
        $body = (string) ($data['body'] ?? '');

        if ($trigger === '' || $title === '' || trim($body) === '') {
            throw new InvalidArgumentException('A snippet needs a trigger, a title and a body.');
        }

        $ownerStaffId = null;

        if ($scope === TextSnippet::SCOPE_SHARED) {
            $this->authorizeSharedManage($actor);
            $this->assertSharedTriggerFree($trigger);
        } else {
            $ownerStaffId = $this->ownStaff($actor)->id;
        }

        $snippet = TextSnippet::query()->create([
            'scope' => $scope,
            'owner_staff_id' => $ownerStaffId,
            'trigger' => $trigger,
            'title' => $title,
            'body' => $body,
            'specialty' => $data['specialty'] ?? null,
            'active' => true,
        ]);

        $this->audit($snippet, $actor, 'created');

        return $snippet;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(TextSnippet $snippet, User $actor, array $data): TextSnippet
    {
        $this->authorizeManage($snippet, $actor);

        $snippet->forceFill(array_filter([
            'title' => isset($data['title']) ? trim((string) $data['title']) : null,
            'body' => $data['body'] ?? null,
            'specialty' => $data['specialty'] ?? null,
            'active' => array_key_exists('active', $data) ? (bool) $data['active'] : null,
        ], fn ($v): bool => $v !== null))->save();

        $this->audit($snippet->refresh(), $actor, 'updated');

        return $snippet;
    }

    public function delete(TextSnippet $snippet, User $actor): void
    {
        $this->authorizeManage($snippet, $actor);
        $this->audit($snippet, $actor, 'deleted');
        $snippet->delete();
    }

    public function staffFor(User $actor): ?StaffProfile
    {
        return StaffProfile::query()->where('user_id', $actor->id)->first();
    }

    private function ownStaff(User $actor): StaffProfile
    {
        $staff = $this->staffFor($actor);

        if ($staff === null) {
            throw new InvalidArgumentException('Only a staff member can own a personal snippet.');
        }

        return $staff;
    }

    private function authorizeManage(TextSnippet $snippet, User $actor): void
    {
        $this->assertSameTenant($snippet);

        if ($snippet->isShared()) {
            $this->authorizeSharedManage($actor);

            return;
        }

        // Personal: editable ONLY by its owner.
        $staff = $this->staffFor($actor);
        if ($staff === null || $snippet->owner_staff_id !== $staff->id) {
            throw new AuthorizationException('A personal snippet can only be managed by its owner.');
        }
    }

    private function authorizeSharedManage(User $actor): void
    {
        if (! Gate::forUser($actor)->allows('snippet.manage.shared')) {
            throw new AuthorizationException('This user cannot manage shared snippets.');
        }
    }

    private function assertSharedTriggerFree(string $trigger): void
    {
        $exists = TextSnippet::query()
            ->where('scope', TextSnippet::SCOPE_SHARED)
            ->where('trigger', $trigger)
            ->exists();

        if ($exists) {
            throw new InvalidArgumentException("A shared snippet with trigger '{$trigger}' already exists.");
        }
    }

    private function assertSameTenant(TextSnippet $snippet): void
    {
        if ($snippet->tenant_id !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute('text_snippet_id', $snippet->id);
        }
    }

    private function normalizeTrigger(string $trigger): string
    {
        // The '.' is UI sugar; store the bare token, lowercased, alnum only.
        return strtolower(preg_replace('/[^A-Za-z0-9_]+/', '', trim($trigger)) ?? '');
    }

    private function audit(TextSnippet $snippet, User $actor, string $verb): void
    {
        // Shared changes affect everyone (fully audited); personal CRUD is lightly
        // logged. Snippets are NOT patient data -> patient_id is null.
        Event::dispatch(new ClinicalRecordChanged(
            'snippet.'.$snippet->scope.'.'.$verb,
            'text_snippet',
            $snippet->id,
            null,
            $actor,
            [
                'scope' => $snippet->scope,
                'trigger' => $snippet->trigger,
                'title' => $snippet->title,
                'specialty' => $snippet->specialty,
            ],
        ));
    }
}
