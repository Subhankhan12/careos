<?php

namespace Modules\Nursing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Nursing\Services\NurseSyncService;
use Modules\Platform\Models\User;
use Symfony\Component\HttpFoundation\Response;

class NurseSyncController
{
    public function __invoke(Request $request, NurseSyncService $sync): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        if (! $user->tokenCan('nurse:day-pack')) {
            abort(Response::HTTP_FORBIDDEN, 'This token cannot sync nurse actions.');
        }

        /** @var array{actions: list<array<string, mixed>>} $validated */
        $validated = $request->validate([
            'actions' => ['required', 'array'],
            'actions.*.client_uuid' => ['required', 'string', 'max:80'],
            'actions.*.type' => ['required', 'string', 'max:40'],
            'actions.*.payload' => ['required', 'array'],
            'actions.*.device_timestamp' => ['required', 'date'],
            'actions.*.sequence' => ['required', 'integer', 'min:1'],
        ]);

        return response()->json([
            'results' => $sync->sync($user, $validated['actions']),
        ]);
    }
}
