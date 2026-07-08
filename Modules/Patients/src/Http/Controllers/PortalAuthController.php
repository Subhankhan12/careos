<?php

namespace Modules\Patients\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Patients\Services\PortalAccessService;

class PortalAuthController
{
    public function acceptInvite(Request $request, PortalAccessService $portal): JsonResponse
    {
        /** @var array{token: string, otp: string, password: string} $data */
        $data = $request->validate([
            'token' => ['required', 'string'],
            'otp' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $account = $portal->acceptInvite($data['token'], $data['otp'], $data['password']);

        return response()->json([
            'portal_account_id' => $account->id,
            'patient_id' => $account->patient_id,
        ]);
    }

    public function login(Request $request, PortalAccessService $portal): JsonResponse
    {
        /** @var array{email: string, password: string} $data */
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $account = $portal->login($data['email'], $data['password']);

        return response()->json([
            'portal_account_id' => $account->id,
            'patient_id' => $account->patient_id,
        ]);
    }
}
