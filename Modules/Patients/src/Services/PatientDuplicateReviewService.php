<?php

namespace Modules\Patients\Services;

use Illuminate\Support\Collection;
use Modules\Patients\Models\Patient;

class PatientDuplicateReviewService
{
    public function __construct(private readonly DuplicateDetector $detector) {}

    /**
     * @return Collection<int, DuplicateCandidate>
     */
    public function potentialDuplicatesFor(Patient $patient, int $minimumScore = 25): Collection
    {
        return $this->detector
            ->findForPatient($patient)
            ->filter(fn (DuplicateCandidate $candidate): bool => $candidate->score >= $minimumScore)
            ->values();
    }
}
