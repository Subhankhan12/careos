<?php

namespace Modules\Clinical\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Clinical\Models\OrderableItem;
use Modules\Clinical\Services\OrderableItemService;
use Modules\Platform\Models\User;

/**
 * Tenant-authored orderable catalog admin. No licensed code set is bundled — the
 * tenant defines its own list (a small generic starter template can be seeded).
 */
class OrderableItemController
{
    public function index(): Response
    {
        Gate::authorize('order.manage');

        return Inertia::render('Clinical/OrderableItems', [
            'items' => OrderableItem::query()
                ->orderBy('category')
                ->orderBy('name')
                ->get()
                ->map(fn (OrderableItem $i): array => [
                    'id' => $i->id,
                    'category' => $i->category,
                    'code' => $i->code,
                    'name' => $i->name,
                    'specimen_or_modality' => $i->specimen_or_modality,
                    'active' => $i->active,
                ])
                ->all(),
            'storeUrl' => route('clinical.orderable-items.store'),
            'deactivateUrl' => route('clinical.orderable-items.deactivate'),
        ]);
    }

    public function store(Request $request, OrderableItemService $items): RedirectResponse
    {
        $data = $request->validate([
            'category' => ['required', 'string', 'in:lab,imaging,other'],
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'specimen_or_modality' => ['nullable', 'string', 'max:255'],
        ]);

        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $items->create($data, $actor);

        return back();
    }

    public function deactivate(Request $request, OrderableItemService $items): RedirectResponse
    {
        $data = $request->validate(['item_id' => ['required', 'string']]);
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $items->deactivate(OrderableItem::query()->findOrFail($data['item_id']), $actor);

        return back();
    }
}
