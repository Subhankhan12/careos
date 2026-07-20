<?php

namespace Modules\Dental\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Audit\Services\AuditService;
use Modules\Dental\Exceptions\DentalException;
use Modules\Dental\Models\PerioExam;
use Modules\Dental\Models\PerioMeasurement;
use Modules\Patients\Models\Patient;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

/**
 * The periodontal charting record/retrieve service (DENTAL.G6). PURE record + read — NO
 * interpretation logic (no staging, grading, severity, risk, attachment-loss "finding", or
 * auto-flagging of a deepening site).
 *
 *  - {@see recordExam()} appends one immutable {@see PerioExam} + its per-site
 *    {@see PerioMeasurement} rows in one transaction, gated on `dental.chart`, tenant + patient
 *    scoped, and audited (`dental.perio_charted`). A re-exam is a NEW exam.
 *  - {@see examsFor()} / {@see siteHistory()} read the patient's perio exams (all raw), gated on
 *    `patient.view`, and write a patient-scoped `read` audit row (clinical data).
 *
 * Everything is tenant-scoped and fail-closed: the actor and patient must belong to the current
 * tenant, and both models (BelongsToTenant) confine every query.
 */
class PerioChartService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuditService $audit,
    ) {}

    /**
     * Record a perio exam with its per-site measurements. Each measurement is
     * `['tooth' => string, 'site' => string, 'pocket_depth_mm' => ?int, 'recession_mm' => ?int,
     *   'bleeding_on_probing' => ?bool, 'mobility' => ?int, 'furcation' => ?int]`. The
     * PerioMeasurement `creating` hook validates FDI id / site / value ranges deterministically
     * (an invalid value throws before any row is inserted — the whole exam rolls back).
     *
     * @param  array<int, array<string, mixed>>  $measurements
     */
    public function recordExam(
        User $actor,
        Patient $patient,
        string $examDate,
        array $measurements,
        ?string $note = null,
    ): PerioExam {
        Gate::forUser($actor)->authorize('dental.chart');
        $this->assertActorTenant($actor);
        $this->assertPatientTenant($patient);

        if ($measurements === []) {
            throw new DentalException('A perio exam needs at least one site measurement.');
        }

        $exam = DB::transaction(function () use ($actor, $patient, $examDate, $measurements, $note): PerioExam {
            $exam = PerioExam::query()->create([
                'patient_id' => $patient->id,
                'examined_by' => $actor->id,
                'exam_date' => $examDate,
                'note' => $note,
            ]);

            foreach ($measurements as $m) {
                PerioMeasurement::query()->create([
                    'perio_exam_id' => $exam->id,
                    'patient_id' => $patient->id,
                    'tooth' => (string) ($m['tooth'] ?? ''),
                    'site' => (string) ($m['site'] ?? ''),
                    'pocket_depth_mm' => $this->intOrNull($m['pocket_depth_mm'] ?? null),
                    'recession_mm' => $this->intOrNull($m['recession_mm'] ?? null),
                    'bleeding_on_probing' => (bool) ($m['bleeding_on_probing'] ?? false),
                    'mobility' => $this->intOrNull($m['mobility'] ?? null),
                    'furcation' => $this->intOrNull($m['furcation'] ?? null),
                ]);
            }

            return $exam;
        });

        $this->audit->record([
            'actor_type' => 'user',
            'actor_id' => (string) $actor->id,
            'action' => 'dental.perio_charted',
            'resource_type' => 'perio_exams',
            'resource_id' => $exam->id,
            'patient_id' => (string) $patient->id,
            'context' => [
                'exam_date' => $examDate,
                'site_count' => count($measurements),
            ],
        ]);

        return $exam->load('measurements');
    }

    /**
     * The patient's perio exams (each with its raw per-site measurements), most recent first.
     *
     * @return Collection<int, PerioExam>
     */
    public function examsFor(User $actor, Patient $patient): Collection
    {
        $this->authorizeRead($actor, $patient, 'perio_exams');

        return PerioExam::query()
            ->where('patient_id', $patient->id)
            ->with('measurements')
            ->orderByDesc('exam_date')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * A single site's RAW measurements over time (oldest first) — the raw numbers in sequence,
     * with NO band, flag, or "worsening" label. The dentist reads the trend and interprets it.
     *
     * @return Collection<int, PerioMeasurement>
     */
    public function siteHistory(User $actor, Patient $patient, string $tooth, string $site): Collection
    {
        $this->authorizeRead($actor, $patient, 'perio_measurements');

        return PerioMeasurement::query()
            ->where('perio_measurements.patient_id', $patient->id)
            ->where('perio_measurements.tooth', $tooth)
            ->where('perio_measurements.site', $site)
            ->join('perio_exams', 'perio_exams.id', '=', 'perio_measurements.perio_exam_id')
            ->orderBy('perio_exams.exam_date')
            ->orderBy('perio_measurements.id')
            ->select('perio_measurements.*')
            ->get();
    }

    private function authorizeRead(User $actor, Patient $patient, string $resourceType): void
    {
        Gate::forUser($actor)->authorize('patient.view');
        $this->assertActorTenant($actor);
        $this->assertPatientTenant($patient);

        // Reading perio data discloses clinical data → patient-scoped read log.
        $this->audit->record([
            'actor_type' => 'user',
            'actor_id' => (string) $actor->id,
            'action' => 'read',
            'resource_type' => $resourceType,
            'resource_id' => (string) $patient->id,
            'patient_id' => (string) $patient->id,
            'context' => ['scope' => 'perio'],
        ]);
    }

    private function intOrNull(mixed $value): ?int
    {
        return ($value === null || $value === '') ? null : (int) $value;
    }

    private function assertActorTenant(User $actor): void
    {
        if ($actor->tenant_id !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute('actor_id', (string) $actor->id);
        }
    }

    private function assertPatientTenant(Patient $patient): void
    {
        if ($patient->tenant_id !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute('patient_id', (string) $patient->id);
        }
    }
}
