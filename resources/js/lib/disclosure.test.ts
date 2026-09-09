import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { disclosureLabel } from './disclosure';

/**
 * QA-FIX.10b (`P9-C2`) — the access log must name the KIND of disclosure it is showing.
 *
 * Half of `P9-C2` was a missing row; the other half is that the surface could not have told the
 * truth about the row even if it had arrived, because the template hardcoded the word "read".
 * `disclosureLabel` is extracted precisely so that half is testable — this repo has no
 * component-render harness (see `a11y-markup.test.ts`), so the source-level assertion at the end
 * is the documented fallback for the wiring itself.
 */

/** The shipped catalogue itself, so these tests cannot pass against phrases they invented. */
const catalogue = JSON.parse(readFileSync(fileURLToPath(new URL('../lang/en.json', import.meta.url)), 'utf8'));

/*
 * A FAITHFUL STAND-IN FOR vue-i18n, not a flat dictionary — this is the part that mattered.
 *
 * vue-i18n resolves a key as a DOTTED PATH into the message tree, and returns the key itself when
 * nothing is there. The first version of this test used a flat `Record<string, string>` lookup, so
 * a key containing a dot resolved happily here and failed in the browser: the page rendered the raw
 * action instead of the phrase. Modelling the real resolution is what makes these tests worth
 * running — a stub that is easier to satisfy than the real thing tests the stub.
 */
function translate(key: string, values: Record<string, unknown>): string {
    let node: unknown = catalogue;
    for (const segment of key.split('.')) {
        if (typeof node !== 'object' || node === null || !(segment in node)) return key;
        node = (node as Record<string, unknown>)[segment];
    }
    if (typeof node !== 'string') return key;

    return node.replace(/\{(\w+)\}/g, (_, name: string) => String(values[name] ?? ''));
}

/** The phrase key a given action maps to — mirrors `disclosureLabel`'s flattening. */
function phraseFor(action: string): string | undefined {
    return catalogue.patients.accessLog.actions[action.replace(/\./g, '_')];
}

describe('disclosureLabel', () => {
    it('names a read as a read', () => {
        expect(disclosureLabel('read', 'document', translate)).toBe('read document');
    });

    it('names a portal release as a release, NOT as a read', () => {
        const label = disclosureLabel('document.shared', 'referral_letter', translate);

        // THE DEFECT, ASSERTED DIRECTLY: this row used to render as "read referral_letter".
        expect(label).toBe('released referral_letter to the patient portal');
        expect(label).not.toContain('read ');
    });

    it('names a withdrawal as a withdrawal', () => {
        expect(disclosureLabel('document.unshared', 'referral_letter', translate))
            .toBe('withdrew referral_letter from the patient portal');
    });

    it('falls back to the raw action for an unphrased one, rather than calling it a read', () => {
        const label = disclosureLabel('records.released_to_insurer', 'document', translate);

        /*
         * The fallback must be UNPOLISHED AND TRUE. If a future action is added to
         * PatientAccessReport::DISCLOSURE_ACTIONS before a phrase exists for it, printing the raw
         * action is honest; printing the read phrasing is the original defect returning.
         */
        expect(label).toBe('records.released_to_insurer document');
        expect(label).not.toContain('read');
    });

    it('renders a missing resource as a dash instead of "undefined"', () => {
        expect(disclosureLabel('read', null, translate)).toBe('read —');
    });

    it('has a phrase for every action the report is allowed to return', () => {
        /*
         * BOTH SIDES OF THE CONTRACT, PINNED TOGETHER. The server decides the set; this catalogue
         * names it. Reading DISCLOSURE_ACTIONS out of the PHP source keeps the two from drifting:
         * adding an action server-side without a phrase fails here rather than shipping a raw
         * action string to a patient.
         */
        const php = readFileSync(
            fileURLToPath(new URL('../../../Modules/Patients/src/Services/PatientAccessReport.php', import.meta.url)),
            'utf8',
        );
        const declaration = php.match(/public const DISCLOSURE_ACTIONS = \[([^\]]*)\];/);
        expect(declaration, 'DISCLOSURE_ACTIONS must be declared on PatientAccessReport').not.toBeNull();

        const actions = [...(declaration as RegExpMatchArray)[1].matchAll(/'([^']+)'/g)].map((m) => m[1]);
        expect(actions.length).toBeGreaterThan(1);

        for (const action of actions) {
            expect(phraseFor(action), `no phrase for "${action}"`).toBeDefined();

            /*
             * ...and it must be REACHABLE through vue-i18n's dotted-path resolution, not merely
             * present in the file. Asserted on the LOOKUP rather than on the rendered label,
             * because `read`'s phrase ("read {resource}") renders identically to the fallback and
             * so cannot distinguish the two — the label test would pass while the key was broken.
             */
            const key = `patients.accessLog.actions.${action.replace(/\./g, '_')}`;
            expect(translate(key, { resource: 'document' }), `"${action}" is unreachable through i18n path resolution`)
                .not.toBe(key);
        }
    });
});

describe('the access log page is wired to it', () => {
    const src = readFileSync(fileURLToPath(new URL('../pages/Patients/AccessLog.vue', import.meta.url)), 'utf8');

    it('renders the recorded action and no longer hardcodes the read phrasing', () => {
        expect(src).toContain('disclosureLabel');
        expect(src).toContain('actionLabel(row.action, row.resource_type)');

        // The exact expression that produced the defect, asserted GONE from the template.
        expect(src).not.toContain("t('patients.accessLog.readAction'");
    });

    it('prints what the log leaves out, beside what it covers', () => {
        // A transparency screen that states one limitation while silently having two is `P9-C2`.
        expect(src).toContain("t('patients.accessLog.scopeActivity')");
        expect(src).toContain("t('patients.accessLog.scopeOperator')");
    });
});
