<?php

namespace Modules\Scheduling\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\DuplicateCandidate;
use Modules\Patients\Services\DuplicateDetector;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Services\TenantContext;
use Modules\Scheduling\Models\Service;
use Modules\Scheduling\Services\AvailableSlotFinder;
use Modules\Scheduling\Services\BookingService;

class PublicBookingController
{
    public function index(Tenant $tenant, TenantContext $tenants): Response
    {
        $tenants->set($tenant);

        return Inertia::render('Public/Book', [
            'tenant' => ['slug' => $tenant->slug, 'name' => $tenant->name],
            'services' => $this->bookableServices(),
            'branches' => Branch::query()->where('active', true)->orderBy('name')->get(['id', 'name'])->all(),
            'slotsUrl' => route('public.booking.slots', $tenant->slug),
            'storeUrl' => route('public.booking.store', $tenant->slug),
        ]);
    }

    public function slots(
        Request $request,
        Tenant $tenant,
        TenantContext $tenants,
        AvailableSlotFinder $slots,
    ): JsonResponse {
        $tenants->set($tenant);
        $data = $request->validate([
            'service_id' => ['required', 'string'],
            'branch_id' => ['required', 'string'],
            'date' => ['required', 'date'],
        ]);
        $service = Service::query()
            ->where('bookable_online', true)
            ->where('active', true)
            ->findOrFail($data['service_id']);

        return response()->json([
            'slots' => $slots->forServiceBranchDate($service, $data['branch_id'], $data['date']),
        ]);
    }

    public function store(
        Request $request,
        Tenant $tenant,
        TenantContext $tenants,
        PatientService $patients,
        DuplicateDetector $duplicates,
        BookingService $bookings,
    ): RedirectResponse {
        $tenants->set($tenant);
        $data = $request->validate([
            'service_id' => ['required', 'string'],
            'branch_id' => ['required', 'string'],
            'starts_at' => ['required', 'date'],
            'resource_ids' => ['required', 'array', 'min:1'],
            'resource_ids.*' => ['required', 'string'],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'date_of_birth' => ['required', 'date'],
            'sex' => ['required', 'string', 'max:50'],
            'email' => ['required', 'email', 'max:255'],
        ]);
        $service = Service::query()
            ->where('bookable_online', true)
            ->where('active', true)
            ->findOrFail($data['service_id']);

        DB::transaction(function () use ($data, $patients, $duplicates, $bookings, $service): void {
            $patient = $this->patientForPublicBooking($data, $patients, $duplicates);

            $bookings->bookOnline(
                $service->id,
                $patient->id,
                $data['branch_id'],
                $data['starts_at'],
                array_values($data['resource_ids']),
            );
        });

        return redirect()->route('public.booking.index', $tenant->slug);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function bookableServices(): array
    {
        return Service::query()
            ->where('bookable_online', true)
            ->where('active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (Service $service): array => [
                'id' => $service->id,
                'name' => $service->name,
                'duration' => $service->default_duration_minutes,
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function patientForPublicBooking(
        array $data,
        PatientService $patients,
        DuplicateDetector $duplicates,
    ): Patient {
        $candidate = $duplicates->findForDemographics($data)
            ->first(fn (DuplicateCandidate $candidate): bool => $candidate->score >= 70);

        if ($candidate instanceof DuplicateCandidate) {
            return $candidate->patient;
        }

        return $patients->create(
            [
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'date_of_birth' => $data['date_of_birth'],
                'sex' => $data['sex'],
            ],
            [['type' => 'email', 'value' => $data['email'], 'is_primary' => true]],
        );
    }
}
