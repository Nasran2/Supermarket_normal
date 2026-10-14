/** Presentation flag. The server always resolves the stock basis independently. */
export const unitsEnabled = () => globalThis.document?.documentElement?.dataset.units !== '0';
export const unitLabel = (unit) => unitsEnabled() ? String(unit ?? '') : '';
export const quantityText = (quantity, unit) => `${Number(quantity)}${unitLabel(unit) ? ` ${unitLabel(unit)}` : ''}`;
