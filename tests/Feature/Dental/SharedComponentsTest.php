<?php

/*
 * DENTAL-B.P1 — the shared dental components (S1 header · S2 extracted FDI arch · S4 stat-tile
 * shell · S5 procedure/phase card).
 *
 * These are STRUCTURAL tests over the component sources, deliberately so. The components are
 * presentational (P0D.GU) and the property under test is an ABSENCE — that no shared surface
 * computes a clinical judgment, and that the S2 extraction moved the arch rather than rewriting
 * it. An absence is proven by reading what the source does and does not contain; a render test
 * would only show today's callers, not the affordances the next caller could reach for.
 *
 * The existing behaviour tests (OdontogramUiTest and the rest of tests/Feature/Dental) are NOT
 * touched by this gate — they still assert the server payload, which this gate does not change.
 *
 * Companion evidence recorded at the gate: the Odontogram chart card was captured from the
 * browser before and after the extraction and is byte-for-byte identical (18109 normalised
 * chars, all 32 teeth, every surface colour, the chart key).
 */

/** Absolute path to a shared dental component. */
function dscPath(string $file): string
{
    return base_path('resources/js/Components/Dental/'.$file);
}

function dscSource(string $file): string
{
    $path = dscPath($file);
    expect(file_exists($path))->toBeTrue("shared dental component {$file} is missing");

    return (string) file_get_contents($path);
}

/**
 * Strip comments so a fence assertion tests AFFORDANCES, not prose.
 *
 * The components carry long comments that NAME the things they refuse to build ("BOP %",
 * "DMFT", "trend arrows", "severity ramp") — that documentation is the point, and it must not
 * be what trips the scan. What matters is whether the CODE offers such an affordance.
 */
function dscStripComments(string $source): string
{
    $source = preg_replace('~/\*.*?\*/~s', ' ', $source) ?? $source;      // block comments
    $source = preg_replace('~<!--.*?-->~s', ' ', $source) ?? $source;     // template comments
    $source = preg_replace('~^\s*//.*$~m', ' ', $source) ?? $source;      // line comments

    return $source;
}

test('S2: the FDI arch was EXTRACTED verbatim — the widget carries the arch, the key and the styles', function () {
    $arch = dscSource('ToothArch.vue');

    // The tooth button, its per-surface mini-diagram and the two state modifiers moved intact.
    expect($arch)->toContain('class="tooth"')
        ->and($arch)->toContain("'tooth-selected': selected === tth")
        ->and($arch)->toContain("'tooth-missing': byTooth[tth]?.whole === 'missing'");
    foreach (['s-b', 's-m', 's-o', 's-d', 's-l'] as $surface) {
        expect($arch)->toContain('class="'.$surface.'"');
    }

    // The anatomical ordering moved unchanged (patient's right descending, left ascending).
    expect($arch)->toContain('const rightQ = upper ? [1, 5] : [4, 8];')
        ->and($arch)->toContain('const leftQ = upper ? [2, 6] : [3, 7];')
        ->and($arch)->toContain('.sort((a, b) => toothNum(b) - toothNum(a))');

    // The scoped styles moved with the markup, so the widget still LOOKS the same.
    expect($arch)->toContain('<style scoped>')
        ->and($arch)->toContain('outline: 2px solid var(--color-euca-500);')
        ->and($arch)->toContain('opacity: 0.45;')
        ->and($arch)->toContain('text-decoration: line-through;');

    // The FACTUAL chart key travelled with the arch.
    expect($arch)->toContain("t('dental.legend.title')")
        ->and($arch)->toContain("t('dental.legend.note')");
});

test('S2: the categorical colour vocabulary is unchanged and its NOT-a-severity-ramp contract survived verbatim', function () {
    $module = dscSource('toothConditionColour.ts');

    // The contract comment is preserved word for word — it is the fence made visible in code.
    expect($module)->toContain('FACTUAL charted-condition legend (categorical, NOT a severity ramp)')
        ->and($module)->toContain('colour means "this is')
        ->and($module)->toContain('No score/grade/gradient anywhere.');

    // Exactly the sixteen conditions the page mapped before the extraction, same values.
    $conditions = [
        'present' => 'transparent', 'missing' => 'transparent', 'unerupted' => 'transparent',
        'implant' => 'var(--color-dental-implant)', 'pontic' => 'var(--color-dental-pontic)',
        'crown' => 'var(--color-dental-crown)', 'root_canal' => 'var(--color-dental-root-canal)',
        'bridge_retainer' => 'var(--color-dental-bridge-retainer)', 'sound' => 'transparent',
        'caries' => 'var(--color-dental-caries)', 'restoration' => 'var(--color-dental-restoration)',
        'fracture' => 'var(--color-dental-fracture)', 'sealant' => 'var(--color-dental-sealant)',
        'veneer' => 'var(--color-dental-veneer)', 'erosion' => 'var(--color-dental-erosion)',
        'abrasion' => 'var(--color-dental-abrasion)',
    ];
    foreach ($conditions as $condition => $value) {
        expect($module)->toContain($condition.": '".$value."',");
    }
    // ...and NOTHING else: the map gained no entry in the move.
    expect(preg_match_all('~^\s+\w+: \'~m', $module))->toBe(count($conditions));

    // Same fallback, same empty-condition behaviour as the page had.
    expect($module)->toContain("if (!condition) return 'transparent';")
        ->and($module)->toContain("?? 'var(--color-dental-unknown)'");

    // A ranking or a gradient would breach the fence for every dental screen at once.
    $code = dscStripComments($module);
    expect($code)->not->toContain('linear-gradient')
        ->and($code)->not->toContain('scale')
        ->and($code)->not->toContain('rank');
});

test('S2: the Odontogram page now CONSUMES the widget and no longer duplicates the arch', function () {
    $page = (string) file_get_contents(base_path('resources/js/pages/Dental/Odontogram.vue'));

    // It delegates to the shared widget, passing the active dentition's tooth set.
    expect($page)->toContain("import ToothArch from '@/Components/Dental/ToothArch.vue';")
        ->and($page)->toContain('<ToothArch')
        ->and($page)->toContain(':teeth="teeth[dentition]"')
        ->and($page)->toContain('@select="selectTooth"');

    // The duplicated arch markup and its styles are GONE from the page (moved, not copied).
    expect($page)->not->toContain('class="tooth"')
        ->and($page)->not->toContain('class="s-b"')
        ->and($page)->not->toContain('<style scoped>')
        ->and($page)->not->toContain('const CONDITION_COLOUR');

    // The side panel still reads the SAME shared vocabulary.
    expect($page)->toContain("import { colour } from '@/Components/Dental/toothConditionColour';");
});

test('S4: the stat-tile is a SHELL — it computes nothing and offers no severity or trend affordance', function () {
    $code = dscStripComments(dscSource('ClinicalStatTile.vue'));

    // No computation of any kind: no reactive derivation, no arithmetic, no rounding,
    // no aggregation, no comparison against a threshold.
    foreach (['computed(', 'Math.', '.toFixed', 'parseFloat', 'parseInt', 'Number(', '.reduce(', '.map(', '.filter(', 'toLocaleString'] as $computation) {
        expect($code)->not->toContain($computation);
    }
    expect(preg_match('~[\w)\]]\s*[+*/%]\s*[\w(]~', $code))->toBe(0, 'the stat tile performs arithmetic');
    expect(preg_match('~\b(if|\?)\s*\(?\s*\w+\s*[<>]=?~', $code))->toBe(0, 'the stat tile compares a value against a threshold');

    // No affordance a caller could use to tint, rank or trend the tile.
    foreach (['tone', 'variant', 'status', 'trend', 'direction', 'delta', 'colour', 'color', 'severity'] as $affordance) {
        expect($code)->not->toContain($affordance);
    }

    // It is CLOSED: no slot, so arbitrary content cannot be injected into the tile.
    expect($code)->not->toContain('<slot');

    // The value is a caller-supplied STRING, rendered as received.
    expect($code)->toContain('value?: string | null')
        ->and($code)->toContain('{{ value }}');
});

test('S5: the procedure card does NO money arithmetic — the amount arrives already formatted', function () {
    $code = dscStripComments(dscSource('ProcedureCard.vue'));

    expect($code)->toContain('amount?: string | null')
        ->and($code)->toContain('{{ amount }}');

    // All money math lives in the billing engine and reconciles to the unit; a display card
    // must never re-derive it.
    foreach (['minor', '/ 100', '.toFixed', '.reduce(', 'Math.', 'total', 'sum'] as $arithmetic) {
        expect($code)->not->toContain($arithmetic);
    }
    expect(preg_match('~[\w)\]]\s*[+*/%]\s*[\w(]~', $code))->toBe(0, 'the procedure card performs arithmetic');
});

test('S1: the header shows a RECORDED allergy as a fact — never graded, ranked or restyled by severity', function () {
    $source = dscSource('PatientClinicalHeader.vue');
    $code = dscStripComments($source);

    // Recorded facts (ALLERGY.P1) are rendered as plain text, exactly as documented.
    expect($code)->toContain('{{ allergy.substance }}')
        ->and($code)->toContain('{{ allergy.reaction }}')
        ->and($code)->toContain('{{ allergy.severity }}');

    // The chip's styling is CONSTANT. No class or style binding may reference severity —
    // that is the line between DISPLAYING a recorded grade and APPLYING one.
    preg_match_all('~:(?:class|style)="([^"]*)"~', $code, $bindings);
    foreach ($bindings[1] ?? [] as $binding) {
        expect(str_contains($binding, 'severity'))->toBeFalse("severity drives styling in the header: {$binding}");
    }
    expect($code)->not->toContain('severity ===')
        ->and($code)->not->toContain('sort')
        ->and($code)->not->toContain('cross');

    // Identity is displayed, never derived: no age arithmetic and no date parsing (D-091 —
    // a date-only value parsed here would shift a day for behind-UTC viewers).
    foreach (['new Date', 'Date.now', 'getFullYear', 'computed(', 'Math.'] as $derivation) {
        expect($code)->not->toContain($derivation);
    }
    expect($code)->toContain('age?: string | null');
});

test('the shared dental components introduce NO computed-judgment affordance (DENTAL-BATCH-DIFF §5.1)', function () {
    /*
     * Every token below names something the dental wireframes draw and the audit ruled
     * MUST-NOT-BUILD-AS-DRAWN: AI imaging findings, confidence, computed indices (DMFT, BOP %),
     * scores, grades, risk, flags and trend arrows.
     *
     * `severity` is the one deliberate, narrow allowance and ONLY in PatientClinicalHeader.vue:
     * there it is the RECORDED severity a clinician documented (ALLERGY.P1), displayed verbatim
     * as text. The test above proves it drives no styling and no ordering — displaying a
     * recorded grade is record-not-judge; applying one would not be.
     */
    $forbidden = [
        'ai', 'finding', 'findings', 'detected', 'detection', 'confidence', 'score', 'scored',
        'index', 'dmft', 'grade', 'graded', 'risk', 'flag', 'flagged', 'abnormal', 'trend',
        'worsening', 'improving', 'plateau', 'recommendation', 'recommended', 'priority',
        'rating', 'interpretation', 'diagnosis', 'verdict', 'prognosis', 'differential',
        'watch', 'threshold', 'predicted', 'suggested', 'analysis',
    ];
    // NOTE: 'baseline' is deliberately NOT on this list. It is the Tailwind `items-baseline`
    // alignment utility in these files, not a comparison point — and it is not one of the
    // §5.1 items. Listing it would make the scan fail for a CSS class, which teaches the next
    // author to weaken the scan rather than to respect it.

    $files = glob(base_path('resources/js/Components/Dental/*.{vue,ts}'), GLOB_BRACE);
    expect($files)->not->toBeEmpty('no shared dental components found');

    foreach ($files as $file) {
        $name = basename($file);
        $code = strtolower(dscStripComments((string) file_get_contents($file)));

        foreach ($forbidden as $token) {
            expect(preg_match('~\b'.preg_quote($token, '~').'\b~', $code))
                ->toBe(0, "judgment affordance '{$token}' appears in {$name}");
        }

        /*
         * The compound §5.1 phrases again, with all non-alphanumerics stripped (added at
         * DENTAL-B.P2, where a mutation proved the gap). A word-boundary scan reads
         * "site to watch" but MISSES `siteToWatch` — which is how an identifier would
         * actually be spelled. Normalising collapses both to the same string.
         *
         * Only COMPOUND phrases go through this pass: Vue's `watch`/`watchEffect` is a
         * legitimate primitive, so bare "watch" stays permitted.
         */
        $squashed = preg_replace('~[^a-z0-9]~', '', $code) ?? $code;
        foreach (['sitetowatch', 'cariesindex', 'dmftindex', 'findingcount', 'severityramp', 'severityscale', 'trendarrow', 'watchlist'] as $compound) {
            expect(str_contains($squashed, $compound))->toBeFalse("compound judgment phrase '{$compound}' appears in {$name}");
        }

        // The narrow allowance, asserted as a rule rather than left implicit.
        if ($name !== 'PatientClinicalHeader.vue') {
            expect(preg_match('~\bseverity\b~', $code))->toBe(0, "'severity' appears in {$name}");
        }
    }
});

test('§6 correctly-more-real: the extraction did not reintroduce DMFT, a finding count or a site-to-watch flag', function () {
    $page = strtolower(dscStripComments((string) file_get_contents(base_path('resources/js/pages/Dental/Odontogram.vue'))));
    $arch = strtolower(dscStripComments(dscSource('ToothArch.vue')));

    foreach ([$page, $arch] as $source) {
        foreach (['dmft', 'dmfs', 'caries index', 'finding', 'flagged', 'site to watch'] as $omission) {
            expect(str_contains($source, $omission))->toBeFalse("'{$omission}' was reintroduced");
        }
    }

    // The chart key still tells the user, on screen, that colour is not severity.
    $en = json_decode((string) file_get_contents(base_path('resources/js/lang/en.json')), true);
    expect($en['dental']['legend']['note'])->toContain('not its severity');
});
