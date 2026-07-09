<?php

namespace Modules\Nursing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\Platform\Models\User;
use Symfony\Component\HttpFoundation\Response;

class NurseAuthController
{
    public function login(Request $request): JsonResponse
    {
        /** @var array{email: string, password: string} $validated */
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()
            ->where('email', $validated['email'])
            ->first();

        if (! $user instanceof User || ! Hash::check($validated['password'], $user->password)) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'The provided credentials are incorrect.');
        }

        if (! $user->isTenantStaff()) {
            abort(Response::HTTP_FORBIDDEN, 'Nurse device login requires a tenant staff account.');
        }

        if (! $user->hasEnabledTwoFactorAuthentication()) {
            abort(Response::HTTP_FORBIDDEN, __('platform::auth.two_factor_required'));
        }

        $expiresAt = now()->addHours((int) config('nursing.device_token_hours', 12));
        $token = $user->createToken('nurse-device', ['nurse:day-pack'], $expiresAt);

        return response()->json([
            'token_type' => 'Bearer',
            'token' => $token->plainTextToken,
            'expires_at' => $expiresAt->toIso8601String(),
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'tenant_id' => $user->tenant_id,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $bearer = $request->bearerToken();

        if ($bearer !== null) {
            PersonalAccessToken::findToken($bearer)?->delete();
        }

        $request->user()?->currentAccessToken()?->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}
