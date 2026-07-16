<?php

namespace Modules\Clinical\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Clinical\Models\Order;
use Modules\Clinical\Models\OrderResult;
use Modules\Clinical\Services\OrderService;
use Modules\Patients\Models\Patient;
use Modules\Platform\Models\User;

/**
 * The clinician "orders to review" worklist — resulted-but-not-reviewed, the
 * analogue of the unsigned-notes worklist. Results are shown RAW and neutral.
 */
class OrdersReviewController
{
    public function __invoke(Request $request, OrderService $orders): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $rows = $orders->toReview($actor)->map(function (Order $order): array {
            $patient = Patient::query()->find($order->patient_id);

            return [
                'id' => $order->id,
                'patient_id' => $order->patient_id,
                'patient' => $patient !== null ? trim($patient->first_name.' '.$patient->last_name) : null,
                'item' => $order->orderableItem?->name,
                'category' => $order->orderableItem?->category,
                'priority' => $order->priority,
                'ordered_at' => $order->ordered_at->toDateTimeString(),
                // Results are shown RAW — no flag/range/abnormal/colour, ever.
                'results' => $order->results->map(fn (OrderResult $r): array => [
                    'id' => $r->id,
                    'value' => $r->result_value,
                    'has_document' => $r->result_document_id !== null,
                    'entered_at' => $r->entered_at->toDateTimeString(),
                ])->all(),
                'chart_url' => route('clinical.chart', $order->patient_id),
            ];
        })->all();

        return Inertia::render('Clinical/OrdersReview', [
            'orders' => $rows,
            'reviewUrl' => route('clinical.orders.review'),
        ]);
    }
}
