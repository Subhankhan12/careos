import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

/**
 * DEPLOY-FIX.2 — the availability screen RENDERS its no-branch empty state.
 *
 * WHY A SECOND TEST AT ALL. `tests/Feature/Qa/AvailabilityWithoutBranchTest.php` proves the server stops
 * returning 404 and sends `filters.branch_id === null`. That is a PAYLOAD assertion, and a payload
 * assertion survives a template mutation — the QA-FIX.5a lesson, and the reason DEPLOY-FIX.1a shipped the
 * same pair for the day-board. This half pins that the page actually renders something for that payload,
 * renders the RIGHT one of its two empty states, and does not render the one sentence that would be a
 * lie without a branch.
 *
 * SOURCE-LEVEL, deliberately: the project has no `@vue/test-utils` harness (only lib-level Vitest specs),
 * and the Pest UI rule forbids markup assertions server-side. Follows `a11y-markup.test.ts` and
 * `day-board-empty-branch.test.ts`.
 *
 * COMMENTS ARE STRIPPED FIRST, AND THAT IS LOAD-BEARING. The page carries a block comment that names
 * `EmptyState`, `filters.branch_id`, `admin.manage` and `/admin/branches` to explain why the state
 * exists. A naive `toContain` would be satisfied by the comment alone, so deleting the real markup would
 * leave this green.
 */

function df2ReadSfc(relative: string): string {
    return readFileSync(fileURLToPath(new URL(relative, import.meta.url)), 'utf8');
}

/**
 * Strip HTML and JS comments. Gate-scoped name on purpose: a subagent overwrote a shared `strip()`
 * helper in QA-FIX.11 and the collision recurred in QA-FIX.12.
 */
function df2StripCommentsForAvailabilityScan(source: string): string {
    return source
        .replace(/<!--[\s\S]*?-->/g, '')
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/^\s*\/\/.*$/gm, '');
}

const raw = df2ReadSfc('./Availability.vue');
const src = df2StripCommentsForAvailabilityScan(raw);

describe('DEPLOY-FIX.2 — the no-branch empty state', () => {
    it('the comment stripper leaves the real markup intact', () => {
        // Size self-check: a runaway greedy match could eat most of the file and still leave a passing
        // subset, so the stripped source must stay substantial and still hold real markup.
        expect(src.length).toBeGreaterThan(raw.length * 0.5);
        expect(src).toContain('<template>');
        expect(src).toContain('<EmptyState');

        // And it really removed something — the page carries a DEPLOY-FIX.2 explanatory comment.
        expect(raw).toContain('DEPLOY-FIX.2');
        expect(src).not.toContain('DEPLOY-FIX.2');
    });

    it('renders an EmptyState when there is no active branch', () => {
        expect(src).toMatch(/v-if="filters\.branch_id === null"/);
        expect(src).toContain('availability.emptyBranchTitle');
        expect(src).toContain('availability.emptyBranchMessage');
    });

    it('gates the call to action on admin.manage, not on the permission that opened the page', () => {
        /*
         * D-214: a link a role cannot open is not rendered. This page is gated on `appointment.manage`,
         * but the link it offers goes to /admin/branches, which is `admin.manage`. Gating the CTA on the
         * page's own permission would offer reception a link straight into a 403.
         */
        expect(src).toMatch(/canSetupBranches \? t\('availability\.emptyBranchAction'\) : undefined/);
        expect(src).toMatch(/canSetupBranches \? '\/admin\/branches' : undefined/);
        expect(src).toContain("permissions?.['admin.manage']");
    });

    it('puts the no-branch state BEFORE the no-resources one, and keeps both', () => {
        /*
         * Ordering matters: a tenant with no branch also has no resources, so checking resources first
         * would tell a practice with no site to go and add a practitioner. The no-branch state must come
         * first; the existing "no resources at this branch" state must survive as the second answer.
         */
        const branchAt = src.indexOf('filters.branch_id === null');
        const resourcesAt = src.indexOf('v-if="resources.length"');
        expect(branchAt).toBeGreaterThan(-1);
        expect(resourcesAt).toBeGreaterThan(branchAt);
        expect(src).toContain('availability.empty');
        expect(src).toContain('availability.emptyHint');
    });

    it('does not render the online-bookings sentence when there is no branch', () => {
        /*
         * D-176 — no unbacked presence. With no branch, neither "online bookings are open" nor
         * "suspended" is true of anything; the controller sends `branchOnlineBookings: false` only
         * because the prop is required, and rendering it would state that a site which does not exist
         * has its online booking switched off.
         *
         * Pinned structurally: the engine block must sit INSIDE the v-else, i.e. after the empty state.
         */
        const emptyAt = src.indexOf('filters.branch_id === null');
        const elseAt = src.indexOf('<template v-else>');
        const engineAt = src.indexOf('branchOnlineBookings ?');
        expect(emptyAt).toBeGreaterThan(-1);
        expect(elseAt).toBeGreaterThan(emptyAt);
        expect(engineAt).toBeGreaterThan(elseAt);
    });

    it('declares branch_id as nullable, so the payload and the template agree', () => {
        expect(src).toMatch(/branch_id: string \| null/);
    });
});

describe('DEPLOY-FIX.2 — the copy exists and says what is absent', () => {
    const en = JSON.parse(df2ReadSfc('../../lang/en.json')) as Record<string, any>;
    const keys = en.availability as Record<string, string>;

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

    it('explains WHY a branch is needed, since this page is about resources rather than sites', () => {
        // The day-board's copy can just say "the board works per branch". Here the operator is on a
        // page about hours, so the message has to connect hours -> resource -> branch or it reads as a
        // non-sequitur.
        expect(keys.emptyBranchMessage).toMatch(/resource/i);
    });
});
