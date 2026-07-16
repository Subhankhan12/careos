<?php

namespace Modules\Clinical\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Clinical\Models\Encounter;
use Modules\Clinical\Models\Order;
use Modules\Clinical\Models\OrderableItem;
use Modules\Clinical\Services\OrderService;
use Modules\Patients\Models\Patient;
use Modules\Platform\Models\User;

class OrderController
{
    public function place(Request $request, OrderService $orders): RedirectResponse
    {
        $data = $request->validate([
            'patient_id' => ['required', 'string'],
            'orderable_item_id' => ['required', 'string'],
            'encounter_id' => ['nullable', 'string'],
            'priority' => ['nullable', 'string', 'in:routine,urgent'],
            'clinical_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $actor = $this->actor($request);
        $patient = Patient::query()->findOrFail($data['patient_id']);
        $item = OrderableItem::query()->findOrFail($data['orderable_item_id']);
        $encounter = ! empty($data['encounter_id']) ? Encounter::query()->findOrFail($data['encounter_id']) : null;

        $orders->place($patient, $encounter, $item, [
            'priority' => $data['priority'] ?? Order::PRIORITY_ROUTINE,
            'clinical_note' => $data['clinical_note'] ?? null,
        ], $actor);

        return redirect()->route('clinical.chart', $patient->id);
    }

    public function transition(Request $request, OrderService $orders): RedirectResponse
    {
        $data = $request->validate([
            'order_id' => ['required', 'string'],
            'status' => ['required', 'string', 'in:collected,in_progress,cancelled'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $order = Order::query()->findOrFail($data['order_id']);
        $orders->transition($order, $data['status'], $this->actor($request), $data['reason'] ?? null);

        return back();
    }

    public function result(Request $request, OrderService $orders): RedirectResponse
    {
        $data = $request->validate([
            'order_id' => ['required', 'string'],
            'value' => ['nullable', 'string', 'max:5000'],
            'document_id' => ['nullable', 'string'],
        ]);

        $order = Order::query()->findOrFail($data['order_id']);
        $orders->recordResult($order, [
            'value' => $data['value'] ?? null,
            'document_id' => $data['document_id'] ?? null,
        ], $this->actor($request));

        return back();
    }

    public function review(Request $request, OrderService $orders): RedirectResponse
    {
        $data = $request->validate(['order_id' => ['required', 'string']]);
        $order = Order::query()->findOrFail($data['order_id']);
        $orders->markReviewed($order, $this->actor($request));

        return back();
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
