import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

/**
 * `QF11a-M1` (QA-FIX.13) — the two Clinical pages render the refusals they receive.
 *
 * SOURCE-LEVEL, AND DELIBERATELY SO. The project has no `@vue/test-utils` or component-render
 * harness (only lib-level Vitest specs), and the Pest UI rule forbids markup assertions
 * server-side — so this follows the `a11y-markup.test.ts` precedent: a static check that the
 * component is actually RENDERED, paired with a Pest feature test that proves the error bag
 * reaches the page. Neither half is sufficient alone, which is the point: QA-FIX.5a's payload
 * test survived a template mutation because it only ever looked at props.
 *
 * COMMENTS ARE STRIPPED BEFORE EVERY ASSERTION, AND THAT IS LOAD-BEARING HERE.
 * Both pages carry a block comment that NAMES `RefusalNotice` to explain why it is there. A naive
 * `toContain('<RefusalNotice')` would be satisfied by the comment alone, so deleting the real tag
 * would leave this suite green — the exact inverse of the PC.P3 trap, where a comment tripped an
 * ABSENCE test. Everything below asserts against comment-stripped source only.
 */

function qf13ReadSfc(relative: string): string {
    return readFileSync(fileURLToPath(new URL(relative, import.meta.url)), 'utf8');
}

/**
 * Strip HTML comments and JS line/block comments. The name is deliberately long and gate-scoped:
 * a subagent overwrote a shared `strip()` helper in QA-FIX.11 and the collision recurred in
 * QA-FIX.12, so this one cannot collide with another spec's helper.
 */
function qf13StripCommentsForRefusalScan(source: string): string {
    return source
        .replace(/<!--[\s\S]*?-->/g, '')
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/^\s*\/\/.*$/gm, '');
}

const PAGES = [
    { name: 'Clinical/Chart.vue', path: './Chart.vue' },
    { name: 'Clinical/OrdersReview.vue', path: './OrdersReview.vue' },
];

describe('QF11a-M1 — both Clinical pages render RefusalNotice', () => {
    for (const page of PAGES) {
        describe(page.name, () => {
            const raw = qf13ReadSfc(page.path);
            const src = qf13StripCommentsForRefusalScan(raw);

            /*
             * THE SIZE SELF-CHECK (RULE 2). If the stripper ever eats the file — a runaway greedy
             * match is the classic way — every `toContain` below would fail loudly rather than
             * silently, but a stripper that ate only MOST of the file could still leave a passing
             * subset. So the stripped source must still be substantial, and must still contain a
             * marker that appears only in real markup.
             */
            it('the comment stripper leaves the real markup intact', () => {
                expect(src.length).toBeGreaterThan(raw.length * 0.5);
                expect(src).toContain('<template>');
                expect(src).toContain('<AppLayout>');
                // And it really did remove something: both pages carry a QF11a-M1 block comment.
                expect(raw).toContain('QF11a-M1');
                expect(src).not.toContain('QF11a-M1');
            });

            it('imports the shared component from its canonical path', () => {
                expect(src).toContain("import RefusalNotice from '@/Components/RefusalNotice.vue'");
            });

            it('RENDERS the tag in the template, not merely imports it', () => {
                // The tag itself, in the stripped source. A deleted <RefusalNotice /> reddens here.
                expect(src).toMatch(/<RefusalNotice\s*\/>/);

                // ...and it is inside the template, not left in the script block.
                const template = src.slice(src.indexOf('<template>'));
                expect(template).toMatch(/<RefusalNotice\s*\/>/);
            });

            it('does not roll its own error rendering beside the shared component', () => {
                /*
                 * D-210's whole point is that one component owns this, so six pages cannot drift
                 * apart in wording or styling. If a future edit adds a bespoke error block here,
                 * that is the `P10-M1` replace-or-duplicate situation and needs a decision, not a
                 * silent second renderer.
                 */
                expect(src).not.toMatch(/v-if="[^"]*\berrors\b[^"]*"/);
            });
        });
    }
});

describe('QF11a-M1 — the component being adopted is the shared one, unmodified', () => {
    const src = qf13StripCommentsForRefusalScan(qf13ReadSfc('../../Components/RefusalNotice.vue'));

    it('still reads the WHOLE error bag rather than named keys', () => {
        // Object.values over the bag — not bag.someKey. Naming keys misses half the refusals:
        // validate() keys by FIELD, withErrors keys by DOMAIN.
        expect(src).toContain('Object.values(bag)');
        expect(src).toContain('page.props.errors');
    });

    it('still renders nothing on an empty bag (D-176 — no unbacked presence)', () => {
        expect(src).toMatch(/v-if="messages\.length"/);
    });

    it('still carries role="alert"', () => {
        expect(src).toContain('role="alert"');
    });
});
