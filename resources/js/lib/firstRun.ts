// First-run / empty-tenant guidance — PURE, presentational logic (POLISH.3). No backend query, no data
// write, no judgment: it only decides whether to show the "Get started" panel and which fixed onboarding
// links a role may see. The server Gate stays authoritative; hiding a link never grants nor blocks access.

export type Operational = {
    appointments: number;
    active_patients: number;
    [key: string]: unknown;
} | null;

export type Financial = {
    outstanding_minor: number;
    [key: string]: unknown;
} | null;

/**
 * A tenant reads as "brand new / empty" when the actor can see the operational figures (i.e. holds
 * reporting.view — the onboarding actor) AND there is no activity today AND nothing is outstanding.
 * Derived PURELY from the landing's EXISTING props — no new query. A returning, populated tenant almost
 * always has an appointment or an outstanding balance, so the panel hides for it; the rare quiet+fully-paid
 * false positive is harmless because the panel is dismissible. Roles without operational (no reporting.view)
 * are not the onboarding actor, so we never guess "empty" for them.
 */
export function isNewTenant(operational: Operational, financial: Financial): boolean {
    if (!operational) {
        return false;
    }
    const noActivity = (operational.appointments ?? 0) === 0 && (operational.active_patients ?? 0) === 0;
    const nothingOwed = !financial || (financial.outstanding_minor ?? 0) === 0;

    return noActivity && nothingOwed;
}

export type SetupStep = { key: string; href: string; permission: string };

// Fixed onboarding steps (NOT a computed recommendation). Each links to an existing route gated by the
// same permission the route already enforces.
export const SETUP_STEPS: SetupStep[] = [
    { key: 'practice', href: '/settings', permission: 'admin.manage' },
    { key: 'team', href: '/admin/roles', permission: 'admin.manage' },
    { key: 'resources', href: '/admin/branches', permission: 'admin.manage' },
    { key: 'patients', href: '/imports', permission: 'data.import' },
    { key: 'appointment', href: '/scheduling/day-board', permission: 'appointment.manage' },
];

/** Only the steps this actor can actually reach — a lower role never gets a link it couldn't already use. */
export function visibleSetupSteps(permissions: Record<string, boolean>): SetupStep[] {
    return SETUP_STEPS.filter((step) => permissions[step.permission] === true);
}
