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

    /**
     * The cheque's wording: Title Case, centavos as a fraction, closed with "Only" so nothing
     * can be appended —
     *
     *   185369.86 → "One Hundred Eighty-Five Thousand Three Hundred Sixty-Nine Pesos and 86/100 Only"
     *   1000.00   → "One Thousand Pesos Only"
     */
    public static function cheque(float|int|string $amount): string
    {
        $total = (int) round(((float) $amount) * 100);
        $pesos = intdiv($total, 100);
        $centavos = $total % 100;

        $words = self::titleCase(self::whole($pesos)).($pesos === 1 ? ' Peso' : ' Pesos');

        if ($centavos > 0) {
            $words .= ' and '.str_pad((string) $centavos, 2, '0', STR_PAD_LEFT).'/100';
        }

        return $words.' Only';
    }

    /** "ONE HUNDRED EIGHTY-FIVE" → "One Hundred Eighty-Five"; hyphenated parts each capitalised. */
    private static function titleCase(string $upper): string
    {
        return implode(' ', array_map(
            fn (string $word) => implode('-', array_map('ucfirst', explode('-', strtolower($word)))),
            explode(' ', $upper),
        ));
    }

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
