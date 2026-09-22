/**
 * The withholding-tax and VAT computation the LDDAP form uses. Mirrors `App\Support\Tax`,
 * which is the tested reference:
 *
 *     withheld = (gross / 1.12) × rate, rounded to two decimals
 *
 * Gross amounts are VAT-inclusive, so the 12% VAT is backed out to reach the taxable base.
 * Done in integer centavos so the result matches the server to the centavo (float math would
 * give 0.30000000000000004 for 0.1 + 0.2).
 */

export const VAT_DIVISOR = 1.12;

export const WTAX_RATES = ['0.01', '0.02', '0.03', '0.05'] as const;
export const VAT_RATES = ['0.01', '0.02', '0.03', '0.05', '0.10', '0.12', '0.30'] as const;

export type WtaxRate = (typeof WTAX_RATES)[number];
export type VatRate = (typeof VAT_RATES)[number];

/** Round half-up to two decimals, as a fixed string. */
export function money(value: number | string): string {
    const n = typeof value === 'string' ? Number(value) : value;
    if (!Number.isFinite(n)) return '0.00';
    // Nudge past binary representation error before rounding, so 2.675 → 2.68.
    const cents = Math.round((Math.abs(n) + Number.EPSILON) * 100);
    return ((n < 0 ? -1 : 1) * cents / 100).toFixed(2);
}

/** The VAT-exclusive base of a VAT-inclusive gross. */
export function taxBase(gross: number | string): string {
    return money(Number(gross) / VAT_DIVISOR);
}

/** The amount withheld from a gross at a rate: (gross / 1.12) × rate, to two decimals. */
export function withheld(gross: number | string, rate: string): string {
    return money((Number(gross) / VAT_DIVISOR) * Number(rate));
}

/** Sum a list of amounts to two decimals. */
export function sum(values: (number | string)[]): string {
    const cents = values.reduce<number>((acc, v) => acc + Math.round(Number(v || 0) * 100), 0);
    return (cents / 100).toFixed(2);
}
