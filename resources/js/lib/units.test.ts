import { describe, expect, it } from 'vitest';
import { isConvertedVital, vitalDisplayValue } from '@/lib/units';

describe('vitalDisplayValue', () => {
    it('converts weight grams to kilograms with one decimal', () => {
        expect(vitalDisplayValue('weight_g', 51340)).toBe('51.3');
        expect(vitalDisplayValue('weight_g', 82000)).toBe('82.0');
        expect(vitalDisplayValue('weight_g', 4800)).toBe('4.8');
    });

    it('converts height millimetres to centimetres with no decimals', () => {
        expect(vitalDisplayValue('height_mm', 1552)).toBe('155');
        expect(vitalDisplayValue('height_mm', 1720)).toBe('172');
    });

    it('renders an already-conventional metric verbatim (no conversion, no interpretation)', () => {
        // mmHg / bpm / % / °C are stored in clinical units — passed through unchanged.
        expect(vitalDisplayValue('systolic', 128)).toBe('128');
        expect(vitalDisplayValue('heart_rate', 72)).toBe('72');
        expect(vitalDisplayValue('spo2', 97)).toBe('97');
        expect(vitalDisplayValue('temperature_c', '36.8')).toBe('36.8');
    });

    it('handles null/empty/unparseable input', () => {
        expect(vitalDisplayValue('weight_g', null)).toBe('');
        expect(vitalDisplayValue('weight_g', undefined)).toBe('');
        expect(vitalDisplayValue('weight_g', '')).toBe('');
        expect(vitalDisplayValue('weight_g', 'n/a')).toBe('n/a');
    });

    it('accepts numeric strings (as vitalsHistory point.value may be a string)', () => {
        expect(vitalDisplayValue('weight_g', '51340')).toBe('51.3');
        expect(vitalDisplayValue('height_mm', '1552')).toBe('155');
    });
});

describe('isConvertedVital', () => {
    it('flags only the base-unit metrics as converted', () => {
        expect(isConvertedVital('weight_g')).toBe(true);
        expect(isConvertedVital('height_mm')).toBe(true);
        expect(isConvertedVital('systolic')).toBe(false);
        expect(isConvertedVital('temperature_c')).toBe(false);
    });
});
