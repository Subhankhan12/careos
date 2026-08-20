// FACTUAL charted-condition legend (categorical, NOT a severity ramp). Each condition maps
// to a distinct hue so a dentist can DISTINGUISH what they charted — colour means "this is
// the condition recorded", never "this is how bad it is". No score/grade/gradient anywhere.
//
// Extracted VERBATIM from Pages/Dental/Odontogram.vue at DENTAL-B.P1 so every dental surface
// that draws teeth shares ONE categorical vocabulary. The contract above travels with it: the
// map is a lookup from a recorded condition to a hue, it is unordered, and no entry ranks
// above another. Adding a gradient, a scale, or a "worse = redder" rule here would breach the
// fence for every screen at once.
const CONDITION_COLOUR: Record<string, string> = {
    // whole-tooth
    present: 'transparent',
    missing: 'transparent',
    unerupted: 'transparent',
    implant: 'var(--color-dental-implant)',
    pontic: 'var(--color-dental-pontic)',
    crown: 'var(--color-dental-crown)',
    root_canal: 'var(--color-dental-root-canal)',
    bridge_retainer: 'var(--color-dental-bridge-retainer)',
    // surface
    sound: 'transparent',
    caries: 'var(--color-dental-caries)',
    restoration: 'var(--color-dental-restoration)',
    fracture: 'var(--color-dental-fracture)',
    sealant: 'var(--color-dental-sealant)',
    veneer: 'var(--color-dental-veneer)',
    erosion: 'var(--color-dental-erosion)',
    abrasion: 'var(--color-dental-abrasion)',
};

export function colour(condition: string | null | undefined): string {
    if (!condition) return 'transparent';
    return CONDITION_COLOUR[condition] ?? 'var(--color-dental-unknown)';
}
