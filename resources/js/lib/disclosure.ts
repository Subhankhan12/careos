/**
 * How a recorded disclosure is named on screen (`P9-C2`, QA-FIX.10b).
 *
 * This lives in `lib/` rather than inside the page because it is the piece that was WRONG, and a
 * pure function is the only part of a Vue page this repo can test properly — the project has no
 * component-render harness (see `a11y-markup.test.ts`).
 *
 * WHAT WAS WRONG. The access log rendered every row as `t('patients.accessLog.readAction')` — the
 * word "read" HARDCODED into the template. That was invisible for as long as the server could only
 * return reads. The moment a release reached the page it would have been LABELLED A READ: a false
 * statement, printed on the one screen whose entire purpose is stating this fact correctly, and a
 * quieter failure than the missing row that `P9-C2` began as.
 *
 * THE FALLBACK PRINTS THE ACTION RATHER THAN GUESSING. An action added to
 * `PatientAccessReport::DISCLOSURE_ACTIONS` before a phrase exists for it renders as its raw action
 * string — unpolished, and TRUE. Falling back to the "read" phrasing is precisely the defect.
 */
export function disclosureLabel(
    action: string,
    resource: string | null,
    translate: (key: string, values: Record<string, unknown>) => string,
): string {
    const subject = resource ?? '—';

    /*
     * THE DOTS MUST GO, AND A BROWSER RUN IS WHAT PROVED IT. vue-i18n resolves a message key as a
     * DOTTED PATH, so `actions.document.shared` is looked up as actions → document → shared and a
     * flat `"document.shared"` entry is never found. Action strings are dotted by convention
     * (`document.shared`), so the key segment is the action with its dots flattened.
     *
     * The first version of this shipped the dotted key. Every unit test passed — the fake translator
     * did a flat lookup — and the page rendered the raw action, because the fallback below is
     * honest. The unit test now models vue-i18n's path resolution so this cannot pass again.
     */
    const key = `patients.accessLog.actions.${action.replace(/\./g, '_')}`;
    const phrase = translate(key, { resource: subject });

    // vue-i18n returns the KEY itself when there is no message for it.
    return phrase === key ? `${action} ${subject}` : phrase;
}
