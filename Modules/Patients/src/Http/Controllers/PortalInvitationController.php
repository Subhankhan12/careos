<?php

namespace Modules\Patients\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PortalAccessService;

class PortalInvitationController
{
    public function __invoke(Request $request, PortalAccessService $portal): JsonResponse
    {
        /** @var array{patient_id: string, email: string} $data */
        $data = $request->validate([
            'patient_id' => ['required', 'string'],
            'email' => ['required', 'email'],
        ]);

        $patient = Patient::query()->whereKey($data['patient_id'])->firstOrFail();
        $invite = $portal->invite($patient, $data['email']);

        return response()->json([
            'portal_account_id' => $invite->account->id,
            'status' => $invite->account->status,
        ], 201);
    }
}
