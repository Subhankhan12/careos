<?php

namespace Modules\Dental\Support;

/**
 * FDI / ISO 3950 two-digit tooth notation — the international standard, chosen as
 * CareOS's canonical per-tooth identifier. First digit = quadrant, second = tooth.
 *
 *  - Permanent (quadrants 1–4, teeth 1–8): 11–18, 21–28, 31–38, 41–48 (32 teeth).
 *  - Primary   (quadrants 5–8, teeth 1–5): 51–55, 61–65, 71–75, 81–85 (20 teeth) —
 *    a family dentist charts children, so primary teeth are first-class.
 *
 * This class is the canonical tooth UNIVERSE (for a chart to render against). A
 * patient's dentition is NOT hardcoded to 32 teeth: it is whatever teeth have been
 * charted — a missing tooth is a charted state, and mixed dentition simply means the
 * patient has both primary and permanent tooth records. Nothing here interprets.
 *
 * Surfaces are the five standard anatomical surfaces (buccal covers facial; lingual
 * covers palatal; occlusal covers incisal) — anatomy, not judgment.
 */
final class ToothNotation
{
    public const NOTATION = 'fdi';

    public const DENTITION_PERMANENT = 'permanent';

    public const DENTITION_PRIMARY = 'primary';

    /** @var list<string> */
    public const SURFACES = ['mesial', 'distal', 'buccal', 'lingual', 'occlusal'];

    /**
     * @return list<string>
     */
    public static function permanent(): array
    {
        return self::generate([1, 2, 3, 4], 8);
    }

    /**
     * @return list<string>
     */
    public static function primary(): array
    {
        return self::generate([5, 6, 7, 8], 5);
    }

    /**
     * The whole canonical tooth universe (permanent + primary).
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [...self::permanent(), ...self::primary()];
    }

    public static function isValid(string $tooth): bool
    {
        return self::dentitionOf($tooth) !== null;
    }

    /**
     * 'permanent' | 'primary' | null (invalid) — derived purely from the FDI id, so
     * dentition never has to be stored or kept in sync.
     */
    public static function dentitionOf(string $tooth): ?string
    {
        if (in_array($tooth, self::permanent(), true)) {
            return self::DENTITION_PERMANENT;
        }

        if (in_array($tooth, self::primary(), true)) {
            return self::DENTITION_PRIMARY;
        }

        return null;
    }

    public static function isSurface(string $surface): bool
    {
        return in_array($surface, self::SURFACES, true);
    }

    /**
     * FDI → US "Universal Numbering System" cross-reference (DENTAL-B.P2).
     *
     * A pure NOTATION MAPPING, not a clinical statement: the same physical tooth has two
     * names, and this returns the other one. It is a deterministic, total function over the
     * valid FDI universe — no lookup table to drift, no judgment, no patient data involved.
     *
     * Source of the mapping (the published definition of the Universal system, ADA):
     *  - PERMANENT teeth are numbered 1–32, starting at the patient's upper-right third
     *    molar (#1), running across the maxillary arch to the upper-left third molar (#16),
     *    dropping to the lower-left third molar (#17) and running back to the lower-right
     *    third molar (#32).
     *      quadrant 1 (upper right, FDI 18→11) → 1→8      : US = 9 − toothNumber
     *      quadrant 2 (upper left,  FDI 21→28) → 9→16     : US = 8 + toothNumber
     *      quadrant 3 (lower left,  FDI 38→31) → 17→24    : US = 25 − toothNumber
     *      quadrant 4 (lower right, FDI 41→48) → 25→32    : US = 24 + toothNumber
     *  - PRIMARY teeth are lettered A–T on the same path, starting at the upper-right second
     *    primary molar (A = FDI 55) through the upper-left second primary molar (J = FDI 65),
     *    then the lower-left second primary molar (K = FDI 75) round to T = FDI 85.
     *
     * CareOS's canonical identifier remains FDI; this exists so a US-trained clinician can
     * read the chart. Returns null for an invalid FDI id.
     */
    public static function universal(string $tooth): ?string
    {
        if (! self::isValid($tooth)) {
            return null;
        }

        $quadrant = (int) $tooth[0];
        $number = (int) $tooth[1];

        $permanent = match ($quadrant) {
            1 => 9 - $number,
            2 => 8 + $number,
            3 => 25 - $number,
            4 => 24 + $number,
            default => null,
        };

        if ($permanent !== null) {
            return (string) $permanent;
        }

        // Primary: the same walk, expressed as letters A–T (index 0–19).
        $index = match ($quadrant) {
            5 => 5 - $number,      // 55→A(0) … 51→E(4)
            6 => 4 + $number,      // 61→F(5) … 65→J(9)
            7 => 15 - $number,     // 75→K(10) … 71→O(14)
            8 => 14 + $number,     // 81→P(15) … 85→T(19)
            default => null,
        };

        return $index === null ? null : chr(ord('A') + $index);
    }

    /**
     * The whole FDI → Universal cross-reference, for a UI that renders both notations.
     *
     * @return array<string, string>
     */
    public static function universalMap(): array
    {
        $map = [];
        foreach (self::all() as $tooth) {
            $universal = self::universal($tooth);
            if ($universal !== null) {
                $map[$tooth] = $universal;
            }
        }

        return $map;
    }

    /**
     * @param  list<int>  $quadrants
     * @return list<string>
     */
    private static function generate(array $quadrants, int $teethPerQuadrant): array
    {
        $ids = [];
        foreach ($quadrants as $quadrant) {
            for ($tooth = 1; $tooth <= $teethPerQuadrant; $tooth++) {
                $ids[] = (string) ($quadrant * 10 + $tooth);
            }
        }

        return $ids;
    }
}
