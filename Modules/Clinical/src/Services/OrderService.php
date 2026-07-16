<?php

namespace Modules\Clinical\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Modules\Clinical\Contracts\LabConnectivity;
use Modules\Clinical\Events\ClinicalRecordChanged;
use Modules\Clinical\Models\Document;
use Modules\Clinical\Models\Encounter;
use Modules\Clinical\Models\Order;
use Modules\Clinical\Models\OrderableItem;
use Modules\Clinical\Models\OrderResult;
use Modules\Patients\Models\Patient;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

/**
 * Structured clinical orders. Places an order, tracks a status lifecycle, and
 * records a MANUAL result that a human reviews. It NEVER interprets a result —
 * values are stored/shown raw, and "reviewed" is a human attestation, never a
 * system judgment.
 */
class OrderService
{
    /**
     * Legal status transitions for the tracking actions (collected/in_progress/
     * cancelled). Resulting and reviewing have their own guarded methods.
     *
     * @var array<string, list<string>>
     */
    private const TRACK_TRANSITIONS = [
        Order::STATUS_ORDERED => [Order::STATUS_COLLECTED, Order::STATUS_IN_PROGRESS, Order::STATUS_CANCELLED],
        Order::STATUS_COLLECTED => [Order::STATUS_IN_PROGRESS, Order::STATUS_CANCELLED],
        Order::STATUS_IN_PROGRESS => [Order::STATUS_CANCELLED],
        Order::STATUS_RESULTED => [],
        Order::STATUS_REVIEWED => [],
        Order::STATUS_CANCELLED => [],
    ];

    /** A result may be recorded while the order is still open. */
    private const RESULTABLE = [Order::STATUS_ORDERED, Order::STATUS_COLLECTED, Order::STATUS_IN_PROGRESS];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly LabConnectivity $lab,
    ) {}

    /**
     * @param  array{priority?: string, clinical_note?: string|null}  $data
     */
    public function place(Patient $patient, ?Encounter $encounter, OrderableItem $item, array $data, User $actor): Order
    {
        $this->authorize($actor);
        $this->assertSameTenant($patient, 'patient_id');
        $this->assertSameTenant($item, 'orderable_item_id');
        $this->assertEncounter($patient, $encounter);

        if (! $item->active) {
            throw new InvalidArgumentException('Orderable item is not active.');
        }

        $priority = (string) ($data['priority'] ?? Order::PRIORITY_ROUTINE);
        if (! in_array($priority, [Order::PRIORITY_ROUTINE, Order::PRIORITY_URGENT], true)) {
            throw new InvalidArgumentException('Unknown order priority.');
        }

        $order = Order::query()->create([
            'patient_id' => $patient->id,
            'encounter_id' => $encounter?->id,
            'orderable_item_id' => $item->id,
            'ordered_by' => $actor->getKey(),
            'ordered_at' => now(),
            'priority' => $priority,
            'clinical_note' => $data['clinical_note'] ?? null,
            'status' => Order::STATUS_ORDERED,
        ]);

        // Transmission is a no-op for the manual implementation — the order is
        // worked by the practice. Nothing leaves the process.
        $this->lab->transmit($order);

        $this->audit($order, $actor, 'order.placed');

        return $order->refresh();
    }

    public function transition(Order $order, string $toStatus, User $actor, ?string $reason = null): Order
    {
        $this->authorize($actor);
        $this->assertSameTenant($order, 'order_id');

        if (! in_array($toStatus, self::TRACK_TRANSITIONS[$order->status] ?? [], true)) {
            throw new InvalidArgumentException("Illegal order transition {$order->status} -> {$toStatus}.");
        }

        if ($toStatus === Order::STATUS_CANCELLED && trim((string) $reason) === '') {
            throw new InvalidArgumentException('Cancellation requires a reason.');
        }

        $order->forceFill([
            'status' => $toStatus,
            'cancelled_reason' => $toStatus === Order::STATUS_CANCELLED ? $reason : $order->cancelled_reason,
        ])->save();

        $this->audit($order->refresh(), $actor, 'order.'.$toStatus, array_filter(['reason' => $reason]));

        return $order;
    }

    /**
     * Record a MANUAL result. Appends an order_result (never edits) and moves the
     * order to 'resulted'. Requires a raw value and/or a linked document.
     *
     * @param  array{value?: string|null, document_id?: string|null}  $data
     */
    public function recordResult(Order $order, array $data, User $actor): OrderResult
    {
        $this->authorize($actor);
        $this->assertSameTenant($order, 'order_id');

        if (! in_array($order->status, self::RESULTABLE, true)) {
            throw new InvalidArgumentException("An order in status {$order->status} cannot be resulted.");
        }

        $value = isset($data['value']) ? trim((string) $data['value']) : '';
        $documentId = $data['document_id'] ?? null;

        if ($value === '' && $documentId === null) {
            throw new InvalidArgumentException('A result requires a value or an attached document.');
        }

        if ($documentId !== null) {
            $document = Document::query()->whereKey($documentId)->first();
            if ($document === null || $document->patient_id !== $order->patient_id) {
                throw CrossTenantReferenceException::forAttribute('result_document_id', (string) $documentId);
            }
        }

        $result = OrderResult::query()->create([
            'order_id' => $order->id,
            'patient_id' => $order->patient_id,
            'result_value' => $value !== '' ? $value : null,
            'result_document_id' => $documentId,
            'entered_by' => $actor->getKey(),
            'entered_at' => now(),
            'source' => OrderResult::SOURCE_MANUAL,
        ]);

        $order->forceFill(['status' => Order::STATUS_RESULTED])->save();

        $this->audit($order->refresh(), $actor, 'order.resulted', ['result_id' => $result->id, 'source' => $result->source]);

        return $result;
    }

    /**
     * Mark an order reviewed. This attests that a HUMAN saw the result — it is
     * NOT a system interpretation of the value.
     */
    public function markReviewed(Order $order, User $actor): Order
    {
        $this->authorize($actor);
        $this->assertSameTenant($order, 'order_id');

        if ($order->status !== Order::STATUS_RESULTED) {
            throw new InvalidArgumentException('Only a resulted order can be marked reviewed.');
        }

        $order->forceFill([
            'status' => Order::STATUS_REVIEWED,
            'reviewed_by' => $actor->getKey(),
            'reviewed_at' => now(),
        ])->save();

        $this->audit($order->refresh(), $actor, 'order.reviewed');

        return $order;
    }

    /**
     * Orders for a patient's chart, read-logged patient-scoped (order + results).
     *
     * @return Collection<int, Order>
     */
    public function chartOrders(Patient $patient, User $actor): Collection
    {
        Gate::forUser($actor)->authorize('patient.view');
        $this->assertSameTenant($patient, 'patient_id');

        $orders = Order::query()
            ->where('patient_id', $patient->id)
            ->with(['results', 'orderableItem'])
            ->orderByDesc('ordered_at')
            ->get();

        foreach ($orders as $order) {
            $order->auditRead(['surface' => 'clinical_chart']);
            foreach ($order->results as $result) {
                $result->auditRead(['surface' => 'clinical_chart']);
            }
        }

        return $orders;
    }

    /**
     * The clinician's "orders to review" worklist: resulted-but-not-reviewed.
     *
     * @return Collection<int, Order>
     */
    public function toReview(User $actor): Collection
    {
        $this->authorize($actor);

        return Order::query()
            ->where('status', Order::STATUS_RESULTED)
            ->with(['results', 'orderableItem'])
            ->orderBy('ordered_at')
            ->get();
    }

    private function authorize(User $actor): void
    {
        if (! Gate::forUser($actor)->allows('order.manage')) {
            throw new AuthorizationException('This user cannot manage clinical orders.');
        }
    }

    private function assertSameTenant(object $model, string $attribute): void
    {
        if (($model->tenant_id ?? null) !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute($attribute, (string) ($model->id ?? ''));
        }
    }

    private function assertEncounter(Patient $patient, ?Encounter $encounter): void
    {
        if ($encounter === null) {
            return;
        }

        $this->assertSameTenant($encounter, 'encounter_id');

        if ($encounter->patient_id !== $patient->id) {
            throw CrossTenantReferenceException::forAttribute('encounter_id', $encounter->id);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function audit(Order $order, User $actor, string $action, array $context = []): void
    {
        Event::dispatch(new ClinicalRecordChanged(
            $action,
            'order',
            $order->id,
            $order->patient_id,
            $actor,
            [
                'orderable_item_id' => $order->orderable_item_id,
                'status' => $order->status,
                'priority' => $order->priority,
                'encounter_id' => $order->encounter_id,
                ...$context,
            ],
        ));
    }
}
