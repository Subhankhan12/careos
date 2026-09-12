/**
 * Form-payload helpers shared by the multi-step wizards.
 *
 * `P1-H2` (QA-FIX.12d, D-229) — DO NOT SUBMIT ROWS NOBODY FILLED IN.
 *
 * A wizard needs a row object to bind its optional inputs to, so the client carries one from the start.
 * On the server those rows' fields are `required_with:<collection>`, and Laravel's
 * `ConvertEmptyStringsToNull` turns `''` into `null` — so an always-present blank row ALWAYS fails
 * validation, on every submission that leaves the optional step alone. Patient registration failed that
 * way on every default path, and because the optional inputs had no `:error` binding the page said
 * nothing at all.
 *
 * This lives here, extracted and tested, because the first version was inline in the component and a
 * mutation proved the test could only see that a string was present — not that anything was filtered.
 */

/**
 * Drop rows in which every one of `keys` is blank.
 *
 * A row the user BEGAN and did not finish is kept, so the server still refuses it — visibly, now that the
 * message has somewhere to render. Only a row with nothing in it at all is removed, because that row is
 * the wizard's scaffolding rather than the user's answer.
 */
export function dropBlankRows<T extends Record<string, unknown>>(rows: T[], keys: Array<keyof T>): T[] {
    return rows.filter((row) => keys.some((key) => String(row[key] ?? '').trim() !== ''));
}
