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
