import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

/**
 * A11Y.1 — presentational guards for the two QA re-audit findings (U-1, U-2).
 *
 * These are deliberately SOURCE-LEVEL assertions, not a mounted-component DOM test: the
 * project has no `@vue/test-utils`/component-render harness (only lib-level Vitest specs),
 * and the Pest UI rule forbids markup assertions server-side. A static check that the a11y
 * attributes/elements are present is the minimal, dependency-free guard against regression —
 * exactly the fallback the gate permits. It asserts presence only; it never touches behaviour,
 * logic, fences, billing, or data.
 */

function read(relative: string): string {
    return readFileSync(fileURLToPath(new URL(relative, import.meta.url)), 'utf8');
}

describe('A11Y.1 U-1 — patient-360 has a navigable heading outline', () => {
    const src = read('./pages/Patients/Show.vue');

    it('has a single top-level h1 (the patient name)', () => {
        expect(src).toContain('<h1');
    });

    it('gives each tab section a semantic h2 (visually hidden, sr-only — no visual change)', () => {
        const h2s = src.match(/<h2 class="sr-only">/g) ?? [];
        // demographics · contacts · coverages · consents · access
        expect(h2s.length).toBeGreaterThanOrEqual(5);
    });

    it('promotes visible sub-section titles to h3', () => {
        expect(src).toContain('<h3');
    });
});

describe('A11Y.1 U-2 — dental chart selects have accessible names', () => {
    const src = read('./pages/Dental/Odontogram.vue');

    it('every <select> carries an :aria-label (accessible name via an i18n key)', () => {
        const selects = src.match(/<select\b[^>]*>/g) ?? [];
        expect(selects.length).toBeGreaterThan(0);
        for (const tag of selects) {
            expect(tag).toContain(':aria-label="t(');
        }
    });
});
