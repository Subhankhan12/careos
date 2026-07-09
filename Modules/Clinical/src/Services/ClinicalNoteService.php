<?php

namespace Modules\Clinical\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Modules\Clinical\Events\ClinicalNoteAmended;
use Modules\Clinical\Events\ClinicalNoteSigned;
use Modules\Clinical\Models\ClinicalNote;
use Modules\Clinical\Models\Encounter;
use Modules\Clinical\Models\NoteTemplate;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

class ClinicalNoteService
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * @param  array<string, mixed>  $sections
     */
    public function saveDraft(
        Encounter $encounter,
        StaffProfile $author,
        array $sections,
        User $actor,
        ?ClinicalNote $draft = null,
        ?NoteTemplate $template = null,
    ): ClinicalNote {
        $this->authorize($actor, 'note.write');
        $tenantId = $this->tenantContext->id();
        $this->assertSameTenant($encounter, 'encounter_id', $tenantId);
        $this->assertSameTenant($author, 'author_id', $tenantId);

        if ($template !== null) {
            $this->assertSameTenant($template, 'template_id', $tenantId);
        }

        if ($draft !== null) {
            $this->assertSameTenant($draft, 'note_id', $tenantId);

            if ($draft->status !== ClinicalNote::STATUS_DRAFT) {
                throw new InvalidArgumentException('Only draft clinical notes are editable.');
            }
        }

        $payload = $this->payloadFromSections($sections, $template);

        return DB::transaction(function () use ($encounter, $author, $draft, $template, $payload): ClinicalNote {
            if ($draft !== null) {
                $draft->forceFill([
                    ...$payload,
                    'template_id' => $template !== null ? $template->id : $draft->template_id,
                ])->save();

                return $draft->refresh();
            }

            return ClinicalNote::query()->create([
                'encounter_id' => $encounter->id,
                'patient_id' => $encounter->patient_id,
                'author_id' => $author->id,
                ...$payload,
                'template_id' => $template?->id,
                'status' => ClinicalNote::STATUS_DRAFT,
                'version' => 1,
            ]);
        });
    }

    public function sign(ClinicalNote $note, User $user): ClinicalNote
    {
        $this->authorize($user, 'note.sign');
        $this->assertSameTenant($note, 'note_id', $this->tenantContext->id());

        if ($note->status === ClinicalNote::STATUS_SIGNED) {
            return $note;
        }

        $this->assertRequiredSectionsPresent($note);

        $signed = DB::transaction(function () use ($note, $user): ClinicalNote {
            $draft = ClinicalNote::query()->whereKey($note->id)->lockForUpdate()->firstOrFail();

            if ($draft->status === ClinicalNote::STATUS_SIGNED) {
                return $draft;
            }

            $draft->forceFill([
                'status' => ClinicalNote::STATUS_SIGNED,
                'signed_at' => now(),
                'signed_by' => $user->id,
            ])->save();

            return $draft->refresh();
        });

        Event::dispatch(new ClinicalNoteSigned($signed, $user));

        return $signed;
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function amend(
        ClinicalNote $signedNote,
        array $changes,
        string $reason,
        StaffProfile $author,
        User $actor,
    ): ClinicalNote {
        $this->authorize($actor, 'note.write');
        $this->assertSameTenant($signedNote, 'note_id', $this->tenantContext->id());
        $this->assertSameTenant($author, 'author_id', $this->tenantContext->id());

        if ($signedNote->status !== ClinicalNote::STATUS_SIGNED) {
            throw new InvalidArgumentException('Only signed notes can be amended.');
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('Amendments require a reason.');
        }

        $sections = [
            'subjective' => $signedNote->subjective,
            'objective' => $signedNote->objective,
            'assessment' => $signedNote->assessment,
            'plan' => $signedNote->plan,
            ...$changes,
        ];

        $amendment = ClinicalNote::query()->create([
            'encounter_id' => $signedNote->encounter_id,
            'patient_id' => $signedNote->patient_id,
            'author_id' => $author->id,
            ...$this->payloadFromSections($sections),
            'template_id' => $signedNote->template_id,
            'status' => ClinicalNote::STATUS_DRAFT,
            'version' => $signedNote->version + 1,
            'supersedes_id' => $signedNote->id,
            'amendment_reason' => $reason,
        ]);

        Event::dispatch(new ClinicalNoteAmended($signedNote, $amendment, $actor, $reason));

        return $amendment;
    }

    /**
     * @return Collection<int, ClinicalNote>
     */
    public function versionsFor(ClinicalNote $note): Collection
    {
        $this->assertSameTenant($note, 'note_id', $this->tenantContext->id());

        $root = $note;
        while ($root->supersedes_id !== null) {
            $root = ClinicalNote::query()->whereKey($root->supersedes_id)->firstOrFail();
        }

        /** @var EloquentCollection<int, ClinicalNote> $chain */
        $chain = new EloquentCollection([$root]);
        $current = $root;

        while (true) {
            $next = ClinicalNote::query()
                ->where('supersedes_id', $current->id)
                ->orderBy('version')
                ->orderBy('created_at')
                ->first();

            if (! $next instanceof ClinicalNote) {
                break;
            }

            $chain->push($next);
            $current = $next;
        }

        return $chain->sortBy('version')->values();
    }

    private function authorize(User $actor, string $ability): void
    {
        if (! Gate::forUser($actor)->allows($ability)) {
            throw new AuthorizationException("This user cannot {$ability}.");
        }
    }

    /**
     * @param  array<string, mixed>  $sections
     * @return array{subjective: string|null, objective: string|null, assessment: string|null, plan: string|null}
     */
    private function payloadFromSections(array $sections, ?NoteTemplate $template = null): array
    {
        return [
            'subjective' => $this->sectionValue($sections, 'subjective', $template?->default_subjective),
            'objective' => $this->sectionValue($sections, 'objective', $template?->default_objective),
            'assessment' => $this->sectionValue($sections, 'assessment', $template?->default_assessment),
            'plan' => $this->sectionValue($sections, 'plan', $template?->default_plan),
        ];
    }

    /**
     * @param  array<string, mixed>  $sections
     */
    private function sectionValue(array $sections, string $key, ?string $default = null): ?string
    {
        if (! array_key_exists($key, $sections)) {
            return $default;
        }

        $value = $sections[$key];

        return $value !== null ? (string) $value : null;
    }

    private function assertRequiredSectionsPresent(ClinicalNote $note): void
    {
        $template = $note->template_id !== null
            ? NoteTemplate::query()->whereKey($note->template_id)->first()
            : null;

        $required = $template instanceof NoteTemplate ? $template->required_sections : [];

        foreach ($required as $section) {
            $value = (string) $note->getAttribute($section);
            if (trim($value) === '') {
                throw new InvalidArgumentException("Clinical note section {$section} is required.");
            }
        }
    }

    private function assertSameTenant(object $model, string $attribute, string $tenantId): void
    {
        if (($model->tenant_id ?? null) !== $tenantId) {
            throw CrossTenantReferenceException::forAttribute($attribute, (string) ($model->id ?? ''));
        }
    }
}
