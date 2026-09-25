import { readFileSync, readdirSync } from 'node:fs';
import { join, relative } from 'node:path';
import { describe, expect, it } from 'vitest';
import { formatDateTime } from './date';
import { formatSwissMoney } from './money';

const pagesRoot = join(process.cwd(), 'resources', 'js', 'pages');

function step3CommentStrippedSource_20260924(source: string): string {
    return source
        .replace(/<!--[\s\S]*?-->/g, '')
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/(^|\s)\/\/.*$/gm, '$1');
}

function step3VuePages_20260924(): Array<{ path: string; source: string }> {
    return (readdirSync(pagesRoot, { recursive: true }) as string[])
        .filter((entry) => entry.endsWith('.vue') && !entry.replace(/\\/g, '/').startsWith('Billing/'))
        .map((entry) => {
            const fullPath = join(pagesRoot, entry);
            return { path: relative(process.cwd(), fullPath).replace(/\\/g, '/'), source: readFileSync(fullPath, 'utf8') };
        });
}

/*
 * These three expressions initialise numeric price inputs; they do not render a money value. A shared
 * formatter includes a currency label and would make the controls invalid. Keep the exceptions exact so a
 * new page-side renderer cannot disappear from the scan accidentally.
 */
const STEP3_NON_RENDERED_INPUT_CONVERSIONS = [
    "props.theatre_time.price_minor !== null ? (props.theatre_time.price_minor / 100).toFixed(2) : ''",
    "item.price_minor !== null ? (item.price_minor / 100).toFixed(2) : ''",
];

function step3RenderedFormatterSource_20260924(source: string): string {
    return STEP3_NON_RENDERED_INPUT_CONVERSIONS.reduce((remaining, inputConversion) => remaining.replaceAll(inputConversion, ''), source);
}

describe('STEP-3 shared formatter sweep', () => {
    it('leaves no native module-local date, time, or rendered money formatter outside the twelve Billing pages', () => {
        const pages = step3VuePages_20260924();
        const paths = pages.map((page) => page.path);

        // D-174 positive control: an empty or mis-rooted scan cannot protect this boundary.
        expect(pages.length).toBeGreaterThan(0);
        expect(paths).toEqual(expect.arrayContaining([
            'resources/js/pages/Telehealth/Sessions.vue',
            'resources/js/pages/Governance/ApprovalQueue.vue',
            'resources/js/pages/Patients/AccessLog.vue',
            'resources/js/pages/Reporting/Dashboard.vue',
        ]));

        for (const page of pages) {
            const stripped = step3CommentStrippedSource_20260924(page.source);
            expect(stripped.length, `${page.path} comment-stripped size`).toBeGreaterThan(page.source.length / 2);

            const renderedSource = step3RenderedFormatterSource_20260924(stripped);
            expect(renderedSource, page.path).not.toMatch(/\bnew\s+Intl\.DateTimeFormat\s*\(/);
            expect(renderedSource, page.path).not.toMatch(/\.toLocale(?:String|DateString|TimeString)\s*\(/);
            expect(renderedSource, page.path).not.toMatch(/\(\s*(?:props\.)?[\w.]+\s*\/\s*100\s*\)\s*\.(?:toFixed|toLocaleString)\s*\(/);
        }
    });

    it('formats a stored UTC instant for the non-UTC practice rather than the viewer', () => {
        const rendered = formatDateTime('2026-09-24 15:14:45', 'Europe/Zurich', 'de-CH');

        expect(rendered).toContain('17:14');
        expect(rendered).toContain('24.09.2026');
    });

    it('renders an already-correct shared money value unchanged', () => {
        expect(formatSwissMoney(74361, 'CHF')).toBe('CHF 743.61');
    });
});
