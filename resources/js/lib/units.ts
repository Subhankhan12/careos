// Clinical-unit DISPLAY conversions for vitals.
//
// Storage stays in base units (weight in grams, height in millimetres — see the
// `vitals` / `visit_vitals` tables); this converts ONLY at render so clinicians read
// the conventional unit. It is a pure unit rescale — RAW values, no interpretation
// (no ranges, flags, colours, "normal/abnormal", or arrows). A metric with no entry
// here is already in a conventional unit and is rendered unchanged.

type Conversion = { factor: number; decimals: number };

// metric key (as emitted in the `vitals` / `vitalsHistory` props) → base→clinical rescale
const CONVERSIONS: Record<string, Conversion> = {
    weight_g: { factor: 1000, decimals: 1 }, // grams → kilograms (51340 → 51.3)
    height_mm: { factor: 10, decimals: 0 }, //  millimetres → centimetres (1552 → 155)
};

/**
 * The display value for a vitals metric, in its clinical unit. Returns the number as a
 * string (never with a unit label — the label is rendered separately). A null/empty
 * value returns the empty string; an unconvertible value is returned verbatim.
 */
export function vitalDisplayValue(metricKey: string, value: number | string | null | undefined): string {
    if (value === null || value === undefined || value === '') return '';
    const num = typeof value === 'number' ? value : Number(value);
    if (Number.isNaN(num)) return String(value);
    const conversion = CONVERSIONS[metricKey];
    if (!conversion) return String(value); // already a conventional unit — render raw
    return (num / conversion.factor).toFixed(conversion.decimals);
}

/** Whether a metric key is display-converted (its stored unit differs from the clinical one). */
export function isConvertedVital(metricKey: string): boolean {
    return metricKey in CONVERSIONS;
}
