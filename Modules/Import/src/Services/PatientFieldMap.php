<?php

namespace Modules\Import\Services;

/**
 * The catalog of CareOS patient fields a CSV column can map to, plus the logic
 * that shapes a row's resolved values into the exact arrays PatientService::create
 * expects (patient + contacts + identifiers + coverages) and the demographics the
 * DuplicateDetector consumes.
 *
 * Nothing here writes to the database — it only shapes data.
 */
class PatientFieldMap
{
    public const GROUP_PATIENT = 'patient';

    public const GROUP_CONTACT = 'contact';

    public const GROUP_IDENTIFIER = 'identifier';

    public const GROUP_COVERAGE = 'coverage';

    /**
     * field key => [group, required]. Only first_name/last_name/date_of_birth are
     * hard-required; sex defaults to 'unknown' so a NOT NULL column never crashes
     * a commit for a CSV that omits it.
     *
     * @var array<string, array{group: string, required: bool}>
     */
    public const FIELDS = [
        'first_name' => ['group' => self::GROUP_PATIENT, 'required' => true],
        'last_name' => ['group' => self::GROUP_PATIENT, 'required' => true],
        'date_of_birth' => ['group' => self::GROUP_PATIENT, 'required' => true],
        'sex' => ['group' => self::GROUP_PATIENT, 'required' => false],
        'gender' => ['group' => self::GROUP_PATIENT, 'required' => false],
        'preferred_language' => ['group' => self::GROUP_PATIENT, 'required' => false],
        'mrn' => ['group' => self::GROUP_PATIENT, 'required' => false],
        'phone' => ['group' => self::GROUP_CONTACT, 'required' => false],
        'email' => ['group' => self::GROUP_CONTACT, 'required' => false],
        'address_line1' => ['group' => self::GROUP_CONTACT, 'required' => false],
        'address_line2' => ['group' => self::GROUP_CONTACT, 'required' => false],
        'address_city' => ['group' => self::GROUP_CONTACT, 'required' => false],
        'address_postal' => ['group' => self::GROUP_CONTACT, 'required' => false],
        'address_country' => ['group' => self::GROUP_CONTACT, 'required' => false],
        'identifier_system' => ['group' => self::GROUP_IDENTIFIER, 'required' => false],
        'identifier_value' => ['group' => self::GROUP_IDENTIFIER, 'required' => false],
        'coverage_payer' => ['group' => self::GROUP_COVERAGE, 'required' => false],
        'coverage_member_id' => ['group' => self::GROUP_COVERAGE, 'required' => false],
        'coverage_type' => ['group' => self::GROUP_COVERAGE, 'required' => false],
        'coverage_plan' => ['group' => self::GROUP_COVERAGE, 'required' => false],
    ];

    /**
     * @return list<string>
     */
    public static function fieldKeys(): array
    {
        return array_keys(self::FIELDS);
    }

    /**
     * @return list<string>
     */
    public static function requiredFields(): array
    {
        return array_keys(array_filter(
            self::FIELDS,
            fn (array $meta): bool => $meta['required'],
        ));
    }

    /**
     * The catalog for the mapping UI.
     *
     * @return list<array{key: string, group: string, required: bool}>
     */
    public function catalog(): array
    {
        $catalog = [];
        foreach (self::FIELDS as $key => $meta) {
            $catalog[] = ['key' => $key, 'group' => $meta['group'], 'required' => $meta['required']];
        }

        return $catalog;
    }

    public function normalizeSex(?string $value): string
    {
        $value = strtolower(trim((string) $value));

        return match ($value) {
            'm', 'male' => 'male',
            'f', 'female' => 'female',
            'o', 'other', 'x', 'divers', 'diverse' => 'other',
            '' => 'unknown',
            default => $value,
        };
    }

    /**
     * Shape resolved (already-parsed) field values into PatientService inputs.
     *
     * @param  array<string, string>  $values  field key => trimmed string value; 'date_of_birth' MUST already be Y-m-d
     * @return array{patient: array<string, mixed>, contacts: list<array<string, mixed>>, identifiers: list<array<string, mixed>>, coverages: list<array<string, mixed>>, demographics: array<string, mixed>}
     */
    public function build(array $values): array
    {
        $get = fn (string $key): string => trim((string) ($values[$key] ?? ''));

        $patient = array_filter([
            'first_name' => $get('first_name'),
            'last_name' => $get('last_name'),
            'date_of_birth' => $get('date_of_birth'),
            'sex' => $this->normalizeSex($get('sex')),
            'gender' => $get('gender') !== '' ? $get('gender') : null,
            'preferred_language' => $get('preferred_language') !== '' ? $get('preferred_language') : null,
            'mrn' => $get('mrn') !== '' ? $get('mrn') : null,
        ], fn ($v): bool => $v !== null);

        $contacts = [];
        if ($get('phone') !== '') {
            $contacts[] = ['type' => 'phone', 'value' => $get('phone'), 'is_primary' => true];
        }
        if ($get('email') !== '') {
            $contacts[] = ['type' => 'email', 'value' => $get('email'), 'is_primary' => true];
        }
        $address = array_filter([
            'line1' => $get('address_line1'),
            'line2' => $get('address_line2'),
            'city' => $get('address_city'),
            'postal' => $get('address_postal'),
            'country' => $get('address_country'),
        ], fn (string $v): bool => $v !== '');
        if ($address !== []) {
            $contacts[] = ['type' => 'address', 'is_primary' => true, ...$address];
        }

        $identifiers = [];
        if ($get('identifier_value') !== '') {
            $identifiers[] = [
                'system' => $get('identifier_system') !== '' ? $get('identifier_system') : 'imported',
                'value' => $get('identifier_value'),
            ];
        }

        $coverages = [];
        if ($get('coverage_payer') !== '' && $get('coverage_member_id') !== '') {
            $coverages[] = array_filter([
                'payer_name' => $get('coverage_payer'),
                'member_id' => $get('coverage_member_id'),
                'coverage_type' => $get('coverage_type') !== '' ? $get('coverage_type') : 'primary',
                'plan' => $get('coverage_plan') !== '' ? $get('coverage_plan') : null,
                'priority' => 1,
            ], fn ($v): bool => $v !== null);
        }

        $demographics = [
            'first_name' => $get('first_name'),
            'last_name' => $get('last_name'),
            'date_of_birth' => $get('date_of_birth') !== '' ? $get('date_of_birth') : null,
            'postal' => $get('address_postal'),
            'city' => $get('address_city'),
            'line1' => $get('address_line1'),
            'identifiers' => $identifiers,
        ];

        return compact('patient', 'contacts', 'identifiers', 'coverages', 'demographics');
    }
}
