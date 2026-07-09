<?php

namespace Modules\Nursing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Modules\Nursing\Services\DayPackService;
use Modules\Platform\Models\User;
use Symfony\Component\HttpFoundation\Response;

class NurseDayPackController
{
    public function __invoke(Request $request, DayPackService $dayPack): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        if (! $user->tokenCan('nurse:day-pack')) {
            abort(Response::HTTP_FORBIDDEN, 'This token cannot sync nurse day-packs.');
        }

        /** @var array{date: string} $validated */
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        return response()->json($dayPack->forNurse(
            $user,
            Carbon::createFromFormat('Y-m-d', $validated['date'])->startOfDay(),
        ));
    }
}
