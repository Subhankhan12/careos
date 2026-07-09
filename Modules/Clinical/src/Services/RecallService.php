<?php

namespace Modules\Clinical\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Modules\Clinical\Events\ClinicalRecordChanged;
use Modules\Clinical\Models\Recall;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

class RecallService
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function transition(Recall $recall, string $status, User $actor): Recall
    {
        $this->authorizeWrite($actor);
        $this->assertSameTenant($recall, 'recall_id');

        $legal = [
            Recall::STATUS_DUE => [
                Recall::STATUS_CONTACTED,
                Recall::STATUS_BOOKED,
                Recall::STATUS_COMPLETED,
                Recall::STATUS_DISMISSED,
            ],
            Recall::STATUS_CONTACTED => [
                Recall::STATUS_BOOKED,
                Recall::STATUS_COMPLETED,
                Recall::STATUS_DISMISSED,
            ],
            Recall::STATUS_BOOKED => [
                Recall::STATUS_COMPLETED,
                Recall::STATUS_DISMISSED,
            ],
        ];

        if (! in_array($status, $legal[$recall->status] ?? [], true)) {
            throw new InvalidArgumentException('Illegal recall transition.');
        }

        $recall->forceFill(['status' => $status])->save();

        Event::dispatch(new ClinicalRecordChanged(
            'recall.'.$status,
            'recall',
            $recall->id,
            $recall->patient_id,
            $actor,
            [
                'rule_id' => $recall->rule_id,
                'due_on' => $recall->due_on->toDateString(),
                'status' => $recall->status,
            ],
        ));

        return $recall->refresh();
    }

    private function authorizeWrite(User $actor): void
    {
        if (! Gate::forUser($actor)->allows('note.write')) {
            throw new AuthorizationException('This user cannot manage recalls.');
        }
    }

    private function assertSameTenant(object $model, string $attribute): void
    {
        if (($model->tenant_id ?? null) !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute($attribute, (string) ($model->id ?? ''));
        }
    }
}
