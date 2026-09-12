import { describe, expect, it } from 'vitest';
import { dropBlankRows } from '@/lib/forms';

/*
| `P1-H2` (QA-FIX.12d, D-229). This is here because a mutation caught the first version of the guard:
| the only assertion was that the component CONTAINED the transform, which a neutered filter satisfies
| just as well. Behaviour is asserted now, not presence.
*/

describe('dropBlankRows', () => {
    it('drops a row the user never touched', () => {
        const rows = [{ system: '', value: '' }];
        expect(dropBlankRows(rows, ['system', 'value'])).toEqual([]);
    });

    it('KEEPS a partially filled row, so the server still refuses it visibly', () => {
        const rows = [{ system: 'AHV', value: '' }];
        expect(dropBlankRows(rows, ['system', 'value'])).toEqual([{ system: 'AHV', value: '' }]);
    });

    it('keeps a fully filled row', () => {
        const rows = [{ payer_name: 'Helsana', member_id: '123' }];
        expect(dropBlankRows(rows, ['payer_name', 'member_id'])).toHaveLength(1);
    });

    it('treats whitespace as blank — a space is not an answer', () => {
        expect(dropBlankRows([{ system: '   ', value: '\t' }], ['system', 'value'])).toEqual([]);
    });

    it('ignores keys it was not told to consider, so a default does not keep a blank row alive', () => {
        /*
         * THE CASE THAT MADE THE DEFECT INVISIBLE. The coverages row ships with
         * `coverage_type: 'self_pay'` and `priority: 1` already set, so "is any field non-empty?" would
         * answer YES for a row the user never opened. Only the fields the USER fills are considered.
         */
        const rows = [{ payer_name: '', member_id: '', plan: '', coverage_type: 'self_pay', priority: 1 }];
        expect(dropBlankRows(rows, ['payer_name', 'member_id'])).toEqual([]);
    });

    it('keeps rows independently rather than dropping the whole collection', () => {
        const rows = [
            { system: '', value: '' },
            { system: 'AHV', value: '756.1234' },
        ];
        expect(dropBlankRows(rows, ['system', 'value'])).toEqual([{ system: 'AHV', value: '756.1234' }]);
    });

    it('leaves an empty collection empty', () => {
        expect(dropBlankRows([], ['system'])).toEqual([]);
    });
});
