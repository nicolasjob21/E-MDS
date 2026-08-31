<?php

namespace App\Support;

/**
 * Spell a peso amount out in words, as the ACIC form's "AMOUNT IN WORDS" line requires:
 *
 *   3042886.38 → "THREE MILLION FORTY-TWO THOUSAND EIGHT HUNDRED EIGHTY-SIX PESOS AND
 *                 THIRTY-EIGHT CENTAVOS"
 *
 * Written out rather than leaning on `NumberFormatter`, which needs the intl extension and
 * would spell "and" and the hyphens differently from the form the bank accepts.
 */
final class AmountInWords
{
    /** @var list<string> */
    private const UNITS = [
        '', 'ONE', 'TWO', 'THREE', 'FOUR', 'FIVE', 'SIX', 'SEVEN', 'EIGHT', 'NINE', 'TEN',
        'ELEVEN', 'TWELVE', 'THIRTEEN', 'FOURTEEN', 'FIFTEEN', 'SIXTEEN', 'SEVENTEEN',
        'EIGHTEEN', 'NINETEEN',
    ];

    /** @var array<int, string> */
    private const TENS = [
        2 => 'TWENTY', 3 => 'THIRTY', 4 => 'FORTY', 5 => 'FIFTY',
        6 => 'SIXTY', 7 => 'SEVENTY', 8 => 'EIGHTY', 9 => 'NINETY',
    ];

    /** @var list<string> */
    private const SCALES = ['', 'THOUSAND', 'MILLION', 'BILLION', 'TRILLION'];

    public static function pesos(float|int|string $amount): string
    {
        // Round to centavos first, so 0.005 cases don't split between the two halves.
        $total = (int) round(((float) $amount) * 100);
        $pesos = intdiv($total, 100);
        $centavos = $total % 100;

        $words = self::whole($pesos).' PESOS';

        if ($centavos > 0) {
            $words .= ' AND '.self::whole($centavos).' CENTAVOS';
        }

        return $words;
    }

    /** Spell a non-negative whole number. */
    public static function whole(int $number): string
    {
        if ($number === 0) {
            return 'ZERO';
        }

        // Split into groups of three, least significant first, and name each by its scale.
        $groups = [];
        while ($number > 0) {
            $groups[] = $number % 1000;
            $number = intdiv($number, 1000);
        }

        $parts = [];
        foreach (array_reverse($groups, true) as $scale => $group) {
            if ($group === 0) {
                continue;
            }

            $parts[] = trim(self::hundreds($group).' '.(self::SCALES[$scale] ?? ''));
        }

        return implode(' ', $parts);
    }

    /** Spell 1–999. */
    private static function hundreds(int $number): string
    {
        $parts = [];

        if ($number >= 100) {
            $parts[] = self::UNITS[intdiv($number, 100)].' HUNDRED';
            $number %= 100;
        }

        if ($number >= 20) {
            $tens = self::TENS[intdiv($number, 10)];
            $unit = $number % 10;
            // The form hyphenates compound tens: FORTY-TWO, EIGHTY-SIX.
            $parts[] = $unit > 0 ? $tens.'-'.self::UNITS[$unit] : $tens;
        } elseif ($number > 0) {
            $parts[] = self::UNITS[$number];
        }

        return implode(' ', $parts);
    }
}
