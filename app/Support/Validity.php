<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The 90-day cheque validity rule, in one place.
 *
 * A cheque is valid for exactly **90 calendar days** from its cheque date — the date printed
 * on it. Day 0 is the cheque date, the last valid day is cheque date + 90, and from day 91 the
 * cheque is stale. Counted in whole days, never in months, so leap years and month-ends need
 * no special handling.
 *
 * All of it is reckoned in **Asia/Manila**, whatever the app's own timezone: the rule is about
 * the calendar date on a piece of paper in a Philippine office, not about an instant in UTC.
 */
final class Validity
{
    /** Days a cheque stays valid, counted from the cheque date. */
    public const DAYS = 90;

    /** How many days out the one-time expiry alert fires. */
    public const ALERT_DAYS = 10;

    /** The calendar the rule is read against. */
    public const TZ = 'Asia/Manila';

    /** Today's date in Manila. */
    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::TZ)->startOfDay();
    }

    /**
     * Read any date — a string, or a Carbon the model cast in whatever timezone — as the
     * **calendar day it names**, at midnight in Manila.
     *
     * This is what keeps the rule honest. A `date` column comes back as midnight UTC, which is
     * 08:00 the same morning in Manila but would compare as *earlier* than Manila's midnight if
     * the two were subtracted as instants. Taking the Y-m-d first and rebuilding it in Manila
     * means every comparison here is a comparison of calendar days, which is what a cheque's
     * validity actually is.
     */
    public static function date(CarbonInterface|string|null $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        $ymd = $value instanceof CarbonInterface
            ? $value->format('Y-m-d')
            : CarbonImmutable::parse($value)->format('Y-m-d');

        return CarbonImmutable::createFromFormat('Y-m-d H:i:s', $ymd.' 00:00:00', self::TZ);
    }

    /** The last day a cheque dated `$chequeDate` may be released, deposited or encashed. */
    public static function until(CarbonInterface|string|null $chequeDate): ?CarbonImmutable
    {
        return self::date($chequeDate)?->addDays(self::DAYS);
    }

    /**
     * Days left before the cheque goes stale, from today in Manila. 0 means it expires today;
     * a negative number is how many days it has been stale.
     */
    public static function daysLeft(CarbonInterface|string|null $validUntil): ?int
    {
        $until = self::date($validUntil);

        if ($until === null) {
            return null;
        }

        return (int) self::today()->diffInDays($until, false);
    }

    /** Is the cheque past its validity — i.e. is today day 91 or later? */
    public static function isExpired(CarbonInterface|string|null $validUntil): bool
    {
        $left = self::daysLeft($validUntil);

        return $left !== null && $left < 0;
    }

    /** Is the cheque inside the alert window — 10 days or fewer left, expiry included? */
    public static function isExpiringSoon(CarbonInterface|string|null $validUntil): bool
    {
        $left = self::daysLeft($validUntil);

        return $left !== null && $left >= 0 && $left <= self::ALERT_DAYS;
    }

    /** "12 days left" · "Expires today" · "Stale — 5 days ago". */
    public static function countdown(CarbonInterface|string|null $validUntil): ?string
    {
        $left = self::daysLeft($validUntil);

        return match (true) {
            $left === null => null,
            $left < 0 => 'Stale — '.abs($left).' '.(abs($left) === 1 ? 'day' : 'days').' ago',
            $left === 0 => 'Expires today',
            default => $left.' '.($left === 1 ? 'day' : 'days').' left',
        };
    }
}
