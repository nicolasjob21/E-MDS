<?php

namespace App\Support;

/**
 * Two-decimal money arithmetic done in decimal strings (bcmath), never in floats — so a sum of
 * withholdings comes out to the centavo the form shows, not to 0.30000000000000004.
 */
final class Money
{
    /** Normalise any numeric input to a two-decimal string, rounded half-up. */
    public static function of(mixed $value): string
    {
        $raw = is_string($value) ? trim($value) : (string) $value;
        if ($raw === '' || ! is_numeric($raw)) {
            $raw = '0';
        }

        // bcadd with a half-unit nudge is the decimal-safe way to round half-up at scale 2.
        $sign = str_starts_with($raw, '-') ? '-1' : '1';
        $abs = ltrim($raw, '-');
        $rounded = bcdiv(bcadd(bcmul($abs, '100', 10), '0.5', 10), '100', 2);

        return bcmul($rounded, $sign, 2);
    }

    /**
     * Read an amount as a person types it into a search box — "194032", "194,032.00",
     * "₱194,032", "PHP 194 032.50" — as a two-decimal string, or null when the text is not an
     * amount at all (so a search for "LDDAP-0001" never becomes a search for ₱0.00).
     */
    public static function parse(?string $text): ?string
    {
        $raw = preg_replace('/^\s*(?:₱|PHP|P)\s*/iu', '', trim((string) $text)) ?? '';
        $raw = str_replace([',', ' '], '', $raw);

        if ($raw === '' || preg_match('/^\d+(?:\.\d{1,2})?$/', $raw) !== 1) {
            return null;
        }

        return self::of($raw);
    }

    /** @param  iterable<mixed>  $values */
    public static function sum(iterable $values): string
    {
        $total = '0.00';
        foreach ($values as $value) {
            $total = bcadd($total, self::of($value), 2);
        }

        return $total;
    }

    public static function sub(mixed $a, mixed $b): string
    {
        return bcsub(self::of($a), self::of($b), 2);
    }

    /** Is the amount greater than zero? */
    public static function positive(mixed $value): bool
    {
        return bccomp(self::of($value), '0.00', 2) === 1;
    }
}
