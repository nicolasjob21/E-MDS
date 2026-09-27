<?php

namespace App\Support;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

/**
 * The PCG unit list (config/pcg-units.json, through config('pcg_units')): every Unit field in
 * the system is a choice from it, saved as the unit's name exactly as the list spells it.
 */
class PcgUnits
{
    /** @return list<array{label: string, units: list<string>}> the headings, in order */
    public static function groups(): array
    {
        return config('pcg_units');
    }

    /** @return list<string> every unit, in list order */
    public static function all(): array
    {
        return array_merge(...array_column(self::groups(), 'units'));
    }

    /** Exact match, as a dropdown sends it. */
    public static function rule(): In
    {
        return Rule::in(self::all());
    }

    /**
     * The official spelling of a typed unit, ignoring capitalization and extra spaces — how a
     * batch upload's Unit column is matched. Null when it is not a unit on the list.
     */
    public static function canonical(string $typed): ?string
    {
        $key = self::key($typed);
        foreach (self::all() as $unit) {
            if (self::key($unit) === $key) {
                return $unit;
            }
        }

        return null;
    }

    private static function key(string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($name)) ?? '');
    }
}
