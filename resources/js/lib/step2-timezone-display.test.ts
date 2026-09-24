import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { formatDateOnly, formatDateTime } from '@/lib/date';

/*
 * STEP-2 — the page wiring behind the Playwright-rendered proof. Vitest deliberately has no
 * Vue mount harness in this repo; this scan pins the formatter calls in each affected template,
 * while the gate's browser pass proves the text the patient or staff member actually receives.
 */
function step2Read(relative: string): string {
    return readFileSync(fileURLToPath(new URL(relative, import.meta.url)), 'utf8');
}

function step2StripComments(source: string): string {
    return source
        .replace(/<!--[\s\S]*?-->/g, '')
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/^\s*\/\/.*$/gm, '');
}

type Surface = { file: string; formatter: 'date' | 'datetime'; needsTenantZone?: boolean };

const surfaces: Surface[] = [
    { file: '../pages/Patients/Show.vue', formatter: 'datetime', needsTenantZone: true },
    { file: '../pages/Scheduling/AppointmentDetail.vue', formatter: 'datetime', needsTenantZone: true },
    { file: '../pages/Dental/Odontogram.vue', formatter: 'date' },
    { file: '../pages/Dental/PerioChart.vue', formatter: 'datetime', needsTenantZone: true },
    { file: '../pages/Clinical/Chart.vue', formatter: 'date', needsTenantZone: true },
    { file: '../pages/Clinical/NoteEditor.vue', formatter: 'datetime', needsTenantZone: true },
    { file: '../Components/VersionHistory.vue', formatter: 'datetime', needsTenantZone: true },
    { file: '../Components/AllergyRecordPanel.vue', formatter: 'datetime', needsTenantZone: true },
    { file: '../pages/Comms/Inbox.vue', formatter: 'datetime', needsTenantZone: true },
    { file: '../pages/Billing/AccountDetail.vue', formatter: 'datetime', needsTenantZone: true },
    { file: '../pages/Billing/Payments/Record.vue', formatter: 'datetime', needsTenantZone: true },
    { file: '../pages/Billing/Payments/Show.vue', formatter: 'date' },
    { file: '../pages/Billing/Aging.vue', formatter: 'date' },
    { file: '../pages/Billing/Report.vue', formatter: 'date' },
    { file: '../pages/Pharmacy/Dispensing.vue', formatter: 'datetime', needsTenantZone: true },
    { file: '../pages/Pharmacy/Inventory.vue', formatter: 'datetime', needsTenantZone: true },
    { file: '../pages/Pharmacy/Emar.vue', formatter: 'datetime', needsTenantZone: true },
    { file: '../pages/Surgery/Case.vue', formatter: 'datetime', needsTenantZone: true },
    { file: '../pages/Surgery/CaseSupplies.vue', formatter: 'datetime', needsTenantZone: true },
    { file: '../pages/Surgery/Inventory.vue', formatter: 'datetime', needsTenantZone: true },
    { file: '../pages/ED/Triage.vue', formatter: 'datetime', needsTenantZone: true },
    { file: '../pages/ED/Documentation.vue', formatter: 'datetime', needsTenantZone: true },
    { file: '../pages/ED/Disposition.vue', formatter: 'datetime', needsTenantZone: true },
    { file: '../pages/Lab/Orders.vue', formatter: 'datetime', needsTenantZone: true },
    { file: '../pages/Lab/Results.vue', formatter: 'datetime', needsTenantZone: true },
    { file: '../pages/Lab/Review.vue', formatter: 'datetime', needsTenantZone: true },
    { file: '../pages/Lab/Specimens.vue', formatter: 'datetime', needsTenantZone: true },
    { file: '../pages/Radiology/Orders.vue', formatter: 'datetime', needsTenantZone: true },
    { file: '../pages/Radiology/Worklist.vue', formatter: 'datetime', needsTenantZone: true },
    { file: '../pages/Radiology/Study.vue', formatter: 'datetime', needsTenantZone: true },
    { file: '../pages/Radiology/Report.vue', formatter: 'datetime', needsTenantZone: true },
    { file: '../pages/Hospital/Admission.vue', formatter: 'datetime', needsTenantZone: true },
    { file: '../pages/Hospital/DischargeSummary.vue', formatter: 'datetime', needsTenantZone: true },
    { file: '../pages/Hospital/Handover.vue', formatter: 'datetime', needsTenantZone: true },
    { file: '../pages/Hospital/StayChart.vue', formatter: 'datetime', needsTenantZone: true },
    { file: '../pages/Hospital/WardBoard.vue', formatter: 'datetime', needsTenantZone: true },
    { file: '../pages/Portal/Home.vue', formatter: 'datetime', needsTenantZone: true },
    { file: '../pages/Portal/Appointments.vue', formatter: 'datetime', needsTenantZone: true },
    { file: '../pages/Portal/Documents.vue', formatter: 'datetime', needsTenantZone: true },
    { file: '../pages/Portal/Messages.vue', formatter: 'datetime', needsTenantZone: true },
    { file: '../pages/Portal/Consents.vue', formatter: 'datetime', needsTenantZone: true },
];

describe('STEP-2 — tenant-clock display wiring', () => {
    it('runs with a viewer in a different zone from the Europe/Zurich fixture', () => {
        expect(process.env.TZ).toBe('America/Los_Angeles');
    });

    it.each(surfaces)('$file has real template markup and the shared $formatter formatter', ({ file, formatter, needsTenantZone }) => {
        const raw = step2Read(file);
        const source = step2StripComments(raw);

        expect(source.length).toBeGreaterThan(raw.length * 0.5);
        expect(source).toContain('<template>');
        expect(source).toContain(formatter === 'datetime' ? 'formatDateTime(' : 'formatDateOnly(');
        expect(source).toMatch(/(?:locale|dtLocale)\.value|'en-CA'/);
        if (needsTenantZone) {
            expect(source).toMatch(/(?:timezone|tz)\.value/);
        }
    });

    it('renders the Zurich instant and Swiss date rather than the Los Angeles viewer values', () => {
        const instant = formatDateTime('2026-09-04T05:30:00Z', 'Europe/Zurich', 'de-CH');
        const date = formatDateOnly('2026-09-04', 'de-CH');

        expect(instant).toContain('07:30');
        expect(instant).not.toContain('22:30');
        expect(date).toContain('04.09.2026');
    });

    it('uses the practice clock when a date input is initialised', () => {
        const account = step2StripComments(step2Read('../pages/Billing/AccountDetail.vue'));
        const payment = step2StripComments(step2Read('../pages/Billing/Payments/Record.vue'));

        expect(account).toContain('function practiceTodayInput()');
        expect(account).toMatch(/formatDateTime\([\s\S]*?timezone\.value[\s\S]*?'en-CA'/);
        expect(payment).toMatch(/formatDateTime\([\s\S]*?timezone\.value[\s\S]*?'en-CA'/);
    });

    it('keeps the raw-UTC, browser-locale, and ISO-slice regressions out of rendered helpers', () => {
        const admission = step2StripComments(step2Read('../pages/Hospital/Admission.vue'));
        const inbox = step2StripComments(step2Read('../pages/Comms/Inbox.vue'));
        const appointment = step2StripComments(step2Read('../pages/Scheduling/AppointmentDetail.vue'));

        expect(admission).toMatch(/event\.occurred_at\) }}<\/span>/);
        expect(admission).toMatch(/\{\{ fmt\(event\.occurred_at\) \}\}/);
        expect(inbox).toMatch(/function timeLabel\(value: string\): string \{\s*return formatDateTime\(value, timezone\.value, locale\.value/);
        expect(inbox).toContain("thread.last_message_at ? dateTimeLabel(thread.last_message_at) : '—'");
        expect(appointment).toMatch(/function timeOf\(value: string \| null\): string \{\s*return formatDateTime\(value, timezone\.value, locale\.value/);
    });

    it('renders every P2-M3 clinical date through the tenant helpers, including the shared child components', () => {
        const chart = step2StripComments(step2Read('../pages/Clinical/Chart.vue'));
        const noteEditor = step2StripComments(step2Read('../pages/Clinical/NoteEditor.vue'));
        const versions = step2StripComments(step2Read('../Components/VersionHistory.vue'));
        const allergies = step2StripComments(step2Read('../Components/AllergyRecordPanel.vue'));

        expect(chart).toContain('formatDateOnly(patient.date_of_birth, locale)');
        expect(noteEditor).toContain("formatDateTime(new Date().toISOString(), tz.value, dtLocale.value");
        expect(noteEditor).toContain('date: dt(note.signed_at)');
        expect(versions).toContain('dateTime(version.signed_at || version.created_at)');
        expect(allergies).toContain('formatDateTime(iso, timezone.value, locale.value');
    });

    it('renders the live ED elapsed clock and the PWA sync timestamp rather than a frozen or raw value', () => {
        const board = step2StripComments(step2Read('../pages/ED/Board.vue'));
        const pwa = step2StripComments(step2Read('../../../nurse-pwa/src/App.vue'));

        expect(board).toContain('const displayedNow = ref(Date.now())');
        expect(board).toContain('onMounted(() => {');
        expect(board).toContain('const mins = Math.max(0, Math.round((displayedNow.value - new Date(iso).getTime()) / 60000));');
        expect(pwa).toContain('formatVisitTime(lastSyncedAt.value, dayPack.value?.timezone)');
        expect(pwa).toContain("t('sync.lastSynced', { time: lastSynced })");
    });
});
