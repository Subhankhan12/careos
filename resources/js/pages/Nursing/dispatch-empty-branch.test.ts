import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

/**
 * STEP-1 / QF13c-M2 — structure behind the Playwright-rendered empty-state proof.
 *
 * The server test proves the nullable payload. This test pins the template wiring, while the gate's
 * Playwright pass proves the actual browser text and absent CTA for a coordinator. Neither substitutes
 * for the other: a payload assertion cannot render a message, and a source scan must not match a comment.
 */

function qf13cReadDispatchSfc(relative: string): string {
    return readFileSync(fileURLToPath(new URL(relative, import.meta.url)), 'utf8');
}

/** Strip comments before assertions: comments may explain the deliberately absent rendered markup. */
function qf13cStripCommentsForDispatchScan(source: string): string {
    return source
        .replace(/<!--[\s\S]*?-->/g, '')
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/^\s*\/\/.*$/gm, '');
}

const raw = qf13cReadDispatchSfc('./Dispatch.vue');
const src = qf13cStripCommentsForDispatchScan(raw);

describe('STEP-1 / QF13c-M2 — dispatch empty states', () => {
    it('the comment-stripped scan is substantial and contains real markup', () => {
        expect(src.length).toBeGreaterThan(raw.length * 0.5);
        expect(src).toContain('<template>');
        expect(src).toContain('<EmptyState');
        expect(raw).toContain('QF13c-M2');
        expect(src).not.toContain('QF13c-M2');
    });

    it('renders no-active-branch before the honest active-branch-empty state', () => {
        const noBranchAt = src.indexOf('filters.branch_id === null');
        const nothingToDispatchAt = src.indexOf('nurseLanes.length === 0 && unassignedVisits.length === 0');

        expect(noBranchAt).toBeGreaterThan(-1);
        expect(nothingToDispatchAt).toBeGreaterThan(noBranchAt);
        expect(src).toContain('nursing.dispatch.emptyBranchTitle');
        expect(src).toContain('nursing.dispatch.emptyBoardTitle');
    });

    it('does not offer the branch setup route to a coordinator without admin.manage', () => {
        expect(src).toMatch(/canSetupBranches \? t\('nursing\.dispatch\.emptyBranchAction'\) : undefined/);
        expect(src).toMatch(/canSetupBranches \? '\/admin\/branches' : undefined/);
        expect(src).toContain("permissions?.['admin.manage']");
    });

    it('declares branch_id nullable, matching the server empty payload', () => {
        expect(src).toMatch(/branch_id: string \| null/);
    });
});

describe('STEP-1 / QF13c-M2 — dispatch copy', () => {
    const en = JSON.parse(qf13cReadDispatchSfc('../../lang/en.json')) as Record<string, any>;
    const dispatch = en.nursing.dispatch as Record<string, string>;

    it('names the absent active branch and the distinct empty-board state', () => {
        expect(dispatch.emptyBranchTitle).toBeTruthy();
        expect(dispatch.emptyBranchMessage).toMatch(/active branch/i);
        expect(dispatch.emptyBranchAction).toBeTruthy();
        expect(dispatch.emptyBoardTitle).toBeTruthy();
        expect(dispatch.emptyBoardMessage).toMatch(/nurse resources.*planned visits/i);
    });
});
