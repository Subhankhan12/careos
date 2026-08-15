import { describe, expect, it } from 'vitest';
import { formatSwissAmount, formatSwissMoney } from '@/lib/money';

describe('formatSwissAmount', () => {
    it('groups thousands with the Swiss apostrophe and keeps two decimals', () => {
        expect(formatSwissAmount(482000)).toBe("4'820.00");
        expect(formatSwissAmount(123450)).toBe("1'234.50");
        expect(formatSwissAmount(100000000)).toBe("1'000'000.00");
    });

    it('formats small and zero amounts without a separator', () => {
        expect(formatSwissAmount(0)).toBe('0.00');
        expect(formatSwissAmount(5)).toBe('0.05');
        expect(formatSwissAmount(31300)).toBe('313.00');
        expect(formatSwissAmount(99900)).toBe('999.00');
    });

    it('renders negatives (e.g. a payment line) with a leading minus', () => {
        expect(formatSwissAmount(-35000)).toBe('-350.00');
        expect(formatSwissAmount(-1234567)).toBe("-12'345.67");
    });
});

describe('formatSwissMoney', () => {
    it('prefixes the currency code (display only — the minor value is unchanged)', () => {
        expect(formatSwissMoney(482000, 'CHF')).toBe("CHF 4'820.00");
        expect(formatSwissMoney(31300, 'EUR')).toBe('EUR 313.00');
    });
});
