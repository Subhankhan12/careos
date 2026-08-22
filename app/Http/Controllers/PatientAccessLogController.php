<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientAccessReport;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Models\User;

/**
 * The dedicated patient access log + the nDSG Art. 25 / GDPR Art. 15 subject-access export.
 *
 * It lives in the APP LAYER because it composes Patients with the Audit ledger, the same reason
 * `PatientShowController` and `AppointmentDetailController` do (D-017).
 *
 * WHAT THIS SCREEN OWES THE PATIENT IS COMPLETENESS. It reports every `action = 'read'` audit row
 * carrying this patient's id, with NO actor-type whitelist, no surface whitelist and no recency
 * cut-off applied by default — a transparency surface that quietly drops a category of reader is a
 * false assurance, which is worse than no screen at all. Screen and export share ONE query
 * (`PatientAccessReport`), so the exported file cannot disagree with what was on screen.
 *
 * IT MAKES NO JUDGMENT ABOUT ACCESS. There is no "suspicious" flag, no anomaly score, no
 * frequency analysis and no tinting by actor type: a read is a fact, and deciding which reads look
 * wrong is the human reviewer's job, not the system's. The only derivation here is `is_agent`,
 * which reads the RECORDED surface string (the agent tools write surfaces that name themselves)
 * and is an ATTRIBUTION, not a grade.
 *
 * VIEWING THIS LOG IS ITSELF A READ, and so is exporting it: both write exactly one audit row
 * through the existing `auditRead()` path, so they appear in this very log the next time it is
 * opened. No second audit path is introduced.
 */
class PatientAccessLogController extends Controller
{
    /**
     * Surfaces written by the AI tools. Matching on the RECORDED surface is reading the row, not
     * inferring intent — each of these strings is written by an agent tool at the point it reads.
     *
     * @var list<string>
     */
    private const AGENT_SURFACE_MARKERS = ['_agent', 'agent_'];

    public function __invoke(string $patient, Request $request, PatientAccessReport $report): Response
    {
        $record = $this->authorizeAndResolve($patient);

        // Viewing the log is a disclosure of this patient's data: one row, existing path.
        $record->auditRead(['surface' => 'patient_access_log']);

        [$from, $to, $days] = $this->range($request);
        $actorTypes = $this->actorTypes($request);

        $rows = $report->forPatientNewestFirst($record, $from, $to, $actorTypes);

        return Inertia::render('Patients/AccessLog', [
            'patient' => [
                'id' => $record->id,
                'mrn' => $record->mrn,
                'name' => trim($record->first_name.' '.$record->last_name),
                'show_url' => route('patients.show', $record->id),
            ],
            'rows' => $this->rows($rows),
            'filters' => [
                'days' => $days,
                'actor_types' => $actorTypes,
            ],
            /*
             * The chips are built from the actor types ACTUALLY PRESENT in this patient's log —
             * never a hardcoded list, which would silently omit a new kind of reader.
             */
            'actorTypeCounts' => $report->actorTypeCountsFor($record)
                ->map(fn (object $row): array => [
                    'actor_type' => (string) $row->actor_type,
                    'total' => (int) $row->total,
                ])
                ->all(),
            'totals' => [
                'shown' => $rows->count(),
                'distinct_actors' => $report->distinctActorCountFor($record),
            ],
            'actions' => [
                'export_url' => route('patients.access-log.export', $record->id),
            ],
        ]);
    }

    /**
     * The subject-access export: CSV, because it is the format a patient can actually open and a
     * regulator expects — plain text, no tooling, no CareOS account required.
     *
     * It calls the SAME report method as the screen with the SAME filters, so the file is exactly
     * what the requester was looking at. Exporting is itself a disclosure and writes its own audit
     * row through the same `auditRead()` path.
     */
    public function export(string $patient, Request $request, PatientAccessReport $report): HttpResponse
    {
        $record = $this->authorizeAndResolve($patient);

        $record->auditRead(['surface' => 'patient_access_log_export']);

        [$from, $to] = $this->range($request);
        $rows = $report->forPatientNewestFirst($record, $from, $to, $this->actorTypes($request));

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['occurred_at', 'actor_type', 'actor_id', 'actor_name', 'resource_type', 'resource_id', 'surface']);

        foreach ($this->rows($rows) as $row) {
            fputcsv($handle, [
                $row['occurred_at'],
                $row['actor_type'],
                $row['actor_id'] ?? '',
                $row['actor_name'],
                $row['resource_type'] ?? '',
                $row['resource_id'] ?? '',
                $row['surface'] ?? '',
            ]);
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        $filename = 'access-report-'.$record->mrn.'-'.Carbon::now()->toDateString().'.csv';

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * `audit.view` AND `patient.view` — this surface discloses both the audit trail and the
     * patient's identity, so it requires both. That is strictly tighter than the Patient 360 tab,
     * which any `patient.view` user can see today. Both are held by `org_admin` and `him_records`
     * (Health Information / Records) — the roles that actually field a subject-access request.
     *
     * The patient id is resolved from a STRING, never route-model binding: implicit binding of a
     * tenant-scoped model runs before the tenant is identified and 500s (FIX.1). A patient from
     * another tenant is simply not found here — fail closed, 404.
     */
    private function authorizeAndResolve(string $patient): Patient
    {
        Gate::authorize('audit.view');
        Gate::authorize('patient.view');

        return Patient::query()->whereKey($patient)->firstOrFail();
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return list<array<string, mixed>>
     */
    private function rows(Collection $rows): array
    {
        $names = $this->actorNames($rows);

        return $rows->map(function (object $row) use ($names): array {
            $context = $this->context($row);
            $surface = isset($context['surface']) ? (string) $context['surface'] : null;

            return [
                'occurred_at' => (string) $row->occurred_at,
                // The RECORDED actor type, printed as recorded: user, patient, operator, service.
                'actor_type' => (string) $row->actor_type,
                'actor_id' => $row->actor_id !== null ? (string) $row->actor_id : null,
                'actor_name' => $names[(string) $row->actor_type.':'.(string) $row->actor_id] ?? $this->fallbackName($row),
                'resource_type' => $row->resource_type !== null ? (string) $row->resource_type : null,
                'resource_id' => $row->resource_id !== null ? (string) $row->resource_id : null,
                'surface' => $surface,
                // An ATTRIBUTION read off the recorded surface, not a judgment about the read.
                'is_agent' => $surface !== null && $this->looksLikeAgentSurface($surface),
            ];
        })->all();
    }

    private function looksLikeAgentSurface(string $surface): bool
    {
        foreach (self::AGENT_SURFACE_MARKERS as $marker) {
            if (str_contains($surface, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve staff display names in ONE query — the log names people, it does not merely print ids.
     *
     * @param  Collection<int, object>  $rows
     * @return array<string, string>
     */
    private function actorNames(Collection $rows): array
    {
        $userIds = $rows->where('actor_type', 'user')
            ->pluck('actor_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($userIds === []) {
            return [];
        }

        $users = User::query()->whereIn('id', $userIds)->get(['id', 'name']);
        $staff = StaffProfile::query()->whereIn('user_id', $userIds)->get(['user_id', 'display_name', 'first_name', 'last_name']);

        $names = [];
        foreach ($users as $user) {
            $names['user:'.$user->id] = (string) $user->name;
        }

        foreach ($staff as $profile) {
            // Same shape as `NoteEditorController::staffName()` — display_name is non-nullable.
            $display = $profile->display_name !== ''
                ? $profile->display_name
                : trim($profile->first_name.' '.$profile->last_name);

            if ($display !== '') {
                $names['user:'.$profile->user_id] = $display;
            }
        }

        return $names;
    }

    private function fallbackName(object $row): string
    {
        return match ((string) $row->actor_type) {
            'patient' => 'Patient (self)',
            'operator' => 'Operator mode',
            'service', 'system' => 'System',
            default => $row->actor_id !== null ? 'User '.$row->actor_id : 'Unknown',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function context(object $row): array
    {
        if ($row->context === null) {
            return [];
        }

        $decoded = json_decode((string) $row->context, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * The range facet: a plain calendar window in whole days. `all` applies no bound at all —
     * the default, so the log is complete unless the reader narrows it deliberately.
     *
     * @return array{0: string|null, 1: string|null, 2: string}
     */
    private function range(Request $request): array
    {
        $days = (string) $request->query('days', 'all');

        if (! in_array($days, ['7', '30', '90', 'all'], true)) {
            $days = 'all';
        }

        if ($days === 'all') {
            return [null, null, 'all'];
        }

        return [Carbon::now()->subDays((int) $days)->format('Y-m-d H:i:s.u'), null, $days];
    }

    /**
     * @return list<string>
     */
    private function actorTypes(Request $request): array
    {
        $raw = $request->query('actor_types');

        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        return collect(explode(',', $raw))
            ->map(fn (string $value): string => trim($value))
            ->filter(fn (string $value): bool => $value !== '')
            ->unique()
            ->values()
            ->all();
    }
}
