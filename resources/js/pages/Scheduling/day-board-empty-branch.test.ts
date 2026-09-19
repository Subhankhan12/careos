import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

/**
 * DEPLOY-FIX.1a — the day-board RENDERS its no-branch empty state.
 *
 * WHY A SECOND TEST AT ALL. `tests/Feature/Qa/FreshTenantIsUsableTest.php` proves the server stops
 * returning 404 and sends `filters.branch_id === null`. That is a PAYLOAD assertion, and a payload
 * assertion survives a template mutation — QA-FIX.5a learned this the hard way. This half pins that the
 * page actually renders something for that payload.
 *
 * SOURCE-LEVEL, and deliberately so: the project has no `@vue/test-utils` harness (only lib-level Vitest
 * specs), and the Pest UI rule forbids markup assertions server-side. This follows the established
 * `a11y-markup.test.ts` precedent.
 *
 * COMMENTS ARE STRIPPED FIRST, AND THAT IS LOAD-BEARING. The page carries a block comment that names
 * `EmptyState`, `filters.branch_id` and `admin.manage` to explain why the state exists. A naive
 * `toContain` would be satisfied by the comment alone, so deleting the real markup would leave this
 * green — the PC.P3 trap inverted.
 */

function df1aReadSfc(relative: string): string {
    return readFileSync(fileURLToPath(new URL(relative, import.meta.url)), 'utf8');
}

/**
 * Strip HTML and JS comments. Gate-scoped name on purpose: a subagent overwrote a shared `strip()`
 * helper in QA-FIX.11 and the collision recurred in QA-FIX.12.
 */
function df1aStripCommentsForBoardScan(source: string): string {
    return source
        .replace(/<!--[\s\S]*?-->/g, '')
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/^\s*\/\/.*$/gm, '');
}

const raw = df1aReadSfc('./DayBoard.vue');
const src = df1aStripCommentsForBoardScan(raw);

describe('DEPLOY-FIX.1a — the no-branch empty state', () => {
    it('the comment stripper leaves the real markup intact', () => {
        // Size self-check: a runaway greedy match that ate most of the file could still leave a
        // passing subset, so the stripped source must stay substantial and still hold real markup.
        expect(src.length).toBeGreaterThan(raw.length * 0.5);
        expect(src).toContain('<template>');
        expect(src).toContain('<EmptyState');

        // And it really removed something — the page carries a DEPLOY-FIX.1a explanatory comment.
        expect(raw).toContain('DEPLOY-FIX.1a');
        expect(src).not.toContain('DEPLOY-FIX.1a');
    });

    it('renders an EmptyState when there is no active branch', () => {
        expect(src).toMatch(/v-if="filters\.branch_id === null"/);
        expect(src).toContain('scheduling.dayBoard.emptyBranchTitle');
        expect(src).toContain('scheduling.dayBoard.emptyBranchMessage');
    });

    it('gates the call to action on admin.manage, so a role that cannot open it is not offered it', () => {
        /*
         * D-214: a link a role cannot open is not rendered. The page already resolves `canSetupResources`
         * from `admin.manage` for the resources empty state; the branch state must reuse it rather than
         * invent a second notion of who may set the practice up.
         */
        expect(src).toMatch(/canSetupResources \? t\('scheduling\.dayBoard\.emptyBranchAction'\) : undefined/);
        expect(src).toMatch(/canSetupResources \? '\/admin\/branches' : undefined/);
        expect(src).toContain("permissions?.['admin.manage']");
    });

    it('keeps the resources empty state as the SECOND branch of the same chain', () => {
        /*
         * Ordering matters: with no branch there are also no resources, so an unordered pair would show
         * "no bookable resources" to a tenant whose actual problem is that it has no site at all. The
         * no-branch state must come first and the resources state must be its `v-else-if`.
         */
        const branchAt = src.indexOf('filters.branch_id === null');
        const resourcesAt = src.indexOf('resources.length === 0');
        expect(branchAt).toBeGreaterThan(-1);
        expect(resourcesAt).toBeGreaterThan(branchAt);
        expect(src).toMatch(/v-else-if="resources\.length === 0"/);
    });

    it('declares branch_id as nullable, so the payload and the template agree', () => {
        expect(src).toMatch(/branch_id: string \| null/);
    });
});

describe('DEPLOY-FIX.1a — the copy exists and says what is absent', () => {
    const en = JSON.parse(df1aReadSfc('../../lang/en.json')) as Record<string, any>;
    const keys = en.scheduling.dayBoard as Record<string, string>;

    it('ships all three empty-branch keys', () => {
        expect(keys.emptyBranchTitle).toBeTruthy();
        expect(keys.emptyBranchMessage).toBeTruthy();
        expect(keys.emptyBranchAction).toBeTruthy();
    });

    it('states the state of the record rather than implying a check was performed', () => {
        // The QA-FIX.5a rule: say what is absent, do not imply the system looked and found nothing wrong.
        expect(keys.emptyBranchMessage).toMatch(/branch/i);
        expect(keys.emptyBranchMessage).not.toMatch(/\b(checked|verified|scanned|confirmed)\b/i);
    });
});
