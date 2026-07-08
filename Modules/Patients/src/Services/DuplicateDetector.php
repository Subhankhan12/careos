<?php

namespace Modules\Patients\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PatientContact;
use Modules\Patients\Models\PatientIdentifier;

class DuplicateDetector
{
    /**
     * @return Collection<int, DuplicateCandidate>
     */
    public function findForPatient(Patient $patient): Collection
    {
        return $this->findForDemographics($this->demographicsFromPatient($patient), $patient->id);
    }

    /**
     * @param  array<string, mixed>  $demographics
     * @return Collection<int, DuplicateCandidate>
     */
    public function findForDemographics(array $demographics, ?string $excludePatientId = null): Collection
    {
        $patients = Patient::query()
            ->with(['contacts', 'identifiers'])
            ->when($excludePatientId !== null, fn ($query) => $query->whereKeyNot($excludePatientId))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        $fullTextIds = $this->fullTextMatchedIds($demographics, $excludePatientId);

        return $patients
            ->map(fn (Patient $candidate) => $this->score($demographics, $candidate, in_array($candidate->id, $fullTextIds, true)))
            ->sortByDesc(fn (DuplicateCandidate $candidate) => $candidate->score)
            ->values();
    }

    /**
     * Scoring rules are intentionally deterministic and additive:
     * name: exact 35, strong 28, partial 15, FULLTEXT-only 8;
     * DOB: exact 35, within 7 days 20;
     * address: exact postal 20, similar line/city 10;
     * identifier: matching optional identifier 10 (confidence raiser only).
     *
     * @param  array<string, mixed>  $demographics
     */
    public function score(array $demographics, Patient $candidate, bool $fullTextMatched = false): DuplicateCandidate
    {
        $score = 0;
        $reasons = [];

        [$nameScore, $nameReason] = $this->nameScore($demographics, $candidate, $fullTextMatched);
        if ($nameScore > 0 && $nameReason !== null) {
            $score += $nameScore;
            $reasons[] = $nameReason;
        }

        [$dobScore, $dobReason] = $this->dobScore($demographics, $candidate);
        if ($dobScore > 0 && $dobReason !== null) {
            $score += $dobScore;
            $reasons[] = $dobReason;
        }

        [$addressScore, $addressReasons] = $this->addressScore($demographics, $candidate);
        $score += $addressScore;
        $reasons = [...$reasons, ...$addressReasons];

        if ($this->hasMatchingIdentifier($demographics, $candidate)) {
            $score += 10;
            $reasons[] = 'identifier_match_raises_confidence';
        }

        return new DuplicateCandidate(
            $candidate,
            min($score, 100),
            $this->confidence($score),
            $reasons,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function demographicsFromPatient(Patient $patient): array
    {
        $address = $this->firstAddress($patient);

        return [
            'first_name' => $patient->first_name,
            'last_name' => $patient->last_name,
            'date_of_birth' => $patient->date_of_birth,
            'postal' => $address?->postal,
            'city' => $address?->city,
            'line1' => $address?->line1,
            'identifiers' => PatientIdentifier::query()
                ->where('patient_id', $patient->id)
                ->get()
                ->map(fn (PatientIdentifier $identifier): array => [
                    'system' => $identifier->system,
                    'value' => $identifier->value,
                ])
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $demographics
     * @return list<string>
     */
    private function fullTextMatchedIds(array $demographics, ?string $excludePatientId): array
    {
        $query = $this->booleanNameQuery($demographics);

        if ($query === null) {
            return [];
        }

        return Patient::query()
            ->select('id')
            ->whereRaw('MATCH(first_name, last_name) AGAINST (? IN BOOLEAN MODE)', [$query])
            ->when($excludePatientId !== null, fn ($builder) => $builder->whereKeyNot($excludePatientId))
            ->pluck('id')
            ->all();
    }

    /**
     * @param  array<string, mixed>  $demographics
     * @return array{0: int, 1: string|null}
     */
    private function nameScore(array $demographics, Patient $candidate, bool $fullTextMatched): array
    {
        $subjectName = $this->normalizeName(($demographics['first_name'] ?? '').' '.($demographics['last_name'] ?? ''));
        $candidateName = $this->normalizeName($candidate->first_name.' '.$candidate->last_name);

        if ($subjectName === '' || $candidateName === '') {
            return [0, null];
        }

        if ($subjectName === $candidateName) {
            return [35, 'name_exact'];
        }

        $subjectTokens = $this->nameTokens($subjectName);
        $candidateTokens = $this->nameTokens($candidateName);
        $overlap = count(array_intersect($subjectTokens, $candidateTokens));
        $maxTokens = max(count($subjectTokens), count($candidateTokens), 1);
        $ratio = $overlap / $maxTokens;

        if ($ratio >= 0.75 || levenshtein($subjectName, $candidateName) <= 2) {
            return [28, 'name_strong'];
        }

        if ($ratio >= 0.5 || $this->normalizeName((string) ($demographics['last_name'] ?? '')) === $this->normalizeName($candidate->last_name)) {
            return [15, 'name_partial'];
        }

        if ($fullTextMatched) {
            return [8, 'name_fulltext_only'];
        }

        return [0, null];
    }

    /**
     * @param  array<string, mixed>  $demographics
     * @return array{0: int, 1: string|null}
     */
    private function dobScore(array $demographics, Patient $candidate): array
    {
        if (empty($demographics['date_of_birth'])) {
            return [0, null];
        }

        $subjectDob = Carbon::parse($demographics['date_of_birth'])->startOfDay();
        $candidateDob = $candidate->date_of_birth->copy()->startOfDay();

        if ($subjectDob->equalTo($candidateDob)) {
            return [35, 'dob_exact'];
        }

        if (abs($subjectDob->diffInDays($candidateDob, false)) <= 7) {
            return [20, 'dob_near_7_days'];
        }

        return [0, null];
    }

    /**
     * @param  array<string, mixed>  $demographics
     * @return array{0: int, 1: list<string>}
     */
    private function addressScore(array $demographics, Patient $candidate): array
    {
        $address = $this->firstAddress($candidate);
        if ($address === null) {
            return [0, []];
        }

        $score = 0;
        $reasons = [];

        $subjectPostal = $this->normalizeText((string) ($demographics['postal'] ?? ''));
        $candidatePostal = $this->normalizeText((string) ($address->postal ?? ''));

        if ($subjectPostal !== '' && $subjectPostal === $candidatePostal) {
            $score += 20;
            $reasons[] = 'postal_exact';
        }

        $subjectLine = $this->normalizeText((string) ($demographics['line1'] ?? ''));
        $candidateLine = $this->normalizeText((string) ($address->line1 ?? ''));
        $subjectCity = $this->normalizeText((string) ($demographics['city'] ?? ''));
        $candidateCity = $this->normalizeText((string) ($address->city ?? ''));

        if ($subjectLine !== '' && $candidateLine !== '' && $subjectCity !== '' && $subjectCity === $candidateCity) {
            $distance = levenshtein($subjectLine, $candidateLine);
            if ($subjectLine === $candidateLine || $distance <= 4 || str_contains($subjectLine, $candidateLine) || str_contains($candidateLine, $subjectLine)) {
                $score += 10;
                $reasons[] = 'address_line_city_similar';
            }
        }

        return [$score, $reasons];
    }

    /**
     * @param  array<string, mixed>  $demographics
     */
    private function hasMatchingIdentifier(array $demographics, Patient $candidate): bool
    {
        $identifiers = $demographics['identifiers'] ?? [];

        if (! is_array($identifiers) || $identifiers === []) {
            return false;
        }

        foreach ($identifiers as $identifier) {
            if (! is_array($identifier)) {
                continue;
            }

            $system = (string) ($identifier['system'] ?? '');
            $value = (string) ($identifier['value'] ?? '');

            if ($system === '' || $value === '') {
                continue;
            }

            if (PatientIdentifier::query()
                ->where('patient_id', $candidate->id)
                ->where('system', $system)
                ->where('value', $value)
                ->exists()) {
                return true;
            }
        }

        return false;
    }

    private function firstAddress(Patient $patient): ?PatientContact
    {
        return PatientContact::query()
            ->where('patient_id', $patient->id)
            ->where('type', PatientContact::TYPE_ADDRESS)
            ->orderByDesc('is_primary')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $demographics
     */
    private function booleanNameQuery(array $demographics): ?string
    {
        $tokens = $this->nameTokens($this->normalizeName(($demographics['first_name'] ?? '').' '.($demographics['last_name'] ?? '')));

        if ($tokens === []) {
            return null;
        }

        return collect($tokens)
            ->map(fn (string $token): string => '+'.$token.'*')
            ->implode(' ');
    }

    private function confidence(int $score): string
    {
        return match (true) {
            $score >= 80 => 'high',
            $score >= 50 => 'medium',
            default => 'low',
        };
    }

    private function normalizeName(string $value): string
    {
        return $this->normalizeText($value);
    }

    private function normalizeText(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '';

        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }

    /**
     * @return list<string>
     */
    private function nameTokens(string $name): array
    {
        return array_values(array_filter(explode(' ', $name), fn (string $token): bool => $token !== ''));
    }
}
