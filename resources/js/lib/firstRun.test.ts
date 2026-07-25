import { describe, expect, it } from 'vitest';
import { isNewTenant, SETUP_STEPS, visibleSetupSteps } from './firstRun';

describe('isNewTenant (first-run panel show/hide — derived from existing props only)', () => {
    it('is TRUE for an empty tenant: no activity today and nothing outstanding', () => {
        expect(isNewTenant({ appointments: 0, active_patients: 0 }, { outstanding_minor: 0, currency: 'CHF' })).toBe(true);
    });

    it('is TRUE when the actor has no financial prop (no billing.view) but operational is all-zero', () => {
        expect(isNewTenant({ appointments: 0, active_patients: 0 }, null)).toBe(true);
    });

    it('is FALSE for a populated tenant with appointments today', () => {
        expect(isNewTenant({ appointments: 4, active_patients: 2 }, { outstanding_minor: 0, currency: 'CHF' })).toBe(false);
    });

    it('is FALSE for a populated tenant with an outstanding balance (quiet day, but not empty)', () => {
        expect(isNewTenant({ appointments: 0, active_patients: 0 }, { outstanding_minor: 78711, currency: 'CHF' })).toBe(false);
    });

    it('is FALSE when the actor cannot see operational figures (no reporting.view -> not the onboarding actor)', () => {
        expect(isNewTenant(null, null)).toBe(false);
        expect(isNewTenant(null, { outstanding_minor: 0, currency: 'CHF' })).toBe(false);
    });
});

describe('visibleSetupSteps (links are permission-gated)', () => {
    it('shows an org_admin every step', () => {
        const perms = { 'admin.manage': true, 'data.import': true, 'appointment.manage': true };
        expect(visibleSetupSteps(perms).map((s) => s.key)).toEqual(SETUP_STEPS.map((s) => s.key));
    });

    it('hides admin/import steps from a role without those permissions', () => {
        const perms = { 'appointment.manage': true }; // e.g. a clinician
        expect(visibleSetupSteps(perms).map((s) => s.key)).toEqual(['appointment']);
    });

    it('shows nothing to a role with none of the setup permissions', () => {
        expect(visibleSetupSteps({})).toEqual([]);
        expect(visibleSetupSteps({ 'patient.view': true })).toEqual([]);
    });
});
