<?php

namespace Modules\Dental\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Patients\Models\Patient;

/**
 * The dental section landing (DENTAL.G9) — a PATIENT PICKER so the dental vertical is
 * reachable from the top nav (dental work is inherently patient-scoped; there is no
 * patient-independent clinical dental route). PRESENTATIONAL (P0D.GU): it only lists the
 * tenant's patients and links each to that patient's odontogram — it computes nothing.
 *
 * `dental.chart`-gated (the same clinical gate as charting), so it appears for the dentist
 * roles (org_admin / doctor) and is hidden + refused for non-dental roles by URL, matching
 * the role-gated nav. `canManageFees` surfaces the (billing.manage) fee-schedule link only
 * for a user who can actually reach it, so the page has no dead-end.
 */
class DentalLandingController
{
    public function __invoke(Request $request): Response
    {
        Gate::authorize('dental.chart');

        $query = trim((string) $request->query('q', ''));
        $dob = trim((string) $request->query('date_of_birth', ''));

        $patients = Patient::query()
            ->when($query !== '', function ($builder) use ($query): void {
                $builder->where(function ($names) use ($query): void {
                    $names->whereRaw('MATCH(first_name, last_name) AGAINST (? IN BOOLEAN MODE)', [$this->booleanNameQuery($query)])
                        ->orWhere('first_name', 'like', '%'.$query.'%')
                        ->orWhere('last_name', 'like', '%'.$query.'%');
                });
            })
            ->when($dob !== '', fn ($builder) => $builder->whereDate('date_of_birth', Carbon::parse($dob)->toDateString()))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->limit(25)
            ->get()
            ->map(fn (Patient $patient): array => [
                'id' => $patient->id,
                'mrn' => $patient->mrn,
                'name' => trim($patient->first_name.' '.$patient->last_name),
                'date_of_birth' => $patient->date_of_birth->toDateString(),
                'sex' => $patient->sex,
                'chart_url' => route('dental.chart', $patient->id),
            ])
            ->all();

        return Inertia::render('Dental/Index', [
            'filters' => [
                'q' => $query,
                'date_of_birth' => $dob,
            ],
            'patients' => $patients,
            'can_manage_fees' => Gate::allows('billing.manage'),
            'fee_schedule_url' => route('dental.fee-schedule'),
        ]);
    }

    private function booleanNameQuery(string $query): string
    {
        $tokens = array_values(array_filter(preg_split('/\s+/', strtolower(trim($query))) ?: []));

        return collect($tokens)
            ->map(fn (string $token): string => '+'.preg_replace('/[^a-z0-9]/', '', $token).'*')
            ->filter(fn (string $token): bool => $token !== '+*')
            ->implode(' ');
    }
}
