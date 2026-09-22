<?php

namespace App\Support;

/**
 * The withholding-tax and VAT computation the LDDAP form uses.
 *
 * Gross amounts are VAT-inclusive, so the taxable base is the gross with the 12% VAT backed
 * out, and each withholding is a rate on that base:
 *
 *     withheld = (gross / 1.12) × rate, rounded to two decimals
 *
 * e.g. gross 112,000.00 at 0.02 → base 100,000.00 → 2,000.00.
 *
 * Computed in decimals (bcmath) rather than floats so the centavo is exact. The client mirrors
 * this in `resources/js/lib/tax.ts`; this is the tested reference.
 */
final class Tax
{
    public const VAT_DIVISOR = '1.12';

    /** @var list<string> */
    public const WTAX_RATES = ['0.01', '0.02', '0.03', '0.05'];

    /** @var list<string> */
    public const VAT_RATES = ['0.01', '0.02', '0.03', '0.05', '0.10', '0.12', '0.30'];

    /** The VAT-exclusive base of a VAT-inclusive gross, to two decimals. */
    public static function base(mixed $gross): string
    {
        // Divide at a wide scale, then round once, so 1/1.12 does not lose a centavo.
        return Money::of(bcdiv(Money::of($gross), self::VAT_DIVISOR, 10));
    }

    /** The amount withheld from a gross at a rate: (gross / 1.12) × rate, to two decimals. */
    public static function withheld(mixed $gross, string $rate): string
    {
        $base = bcdiv(Money::of($gross), self::VAT_DIVISOR, 10);

        return Money::of(bcmul($base, $rate, 10));
    }
}
