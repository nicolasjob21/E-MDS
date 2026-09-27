<?php

namespace App\Support;

/**
 * The **00-00-00000** format — two digits, two digits, five digits — that the LDDAP Number and
 * the DV Number are both written in. Enforced wherever either is entered; the form inserts the
 * dashes as the digits are typed.
 */
class DashedNumber
{
    /** @return list<string> the validation rules for a field in this format */
    public static function rules(): array
    {
        return ['string', 'regex:/^\d{2}-\d{2}-\d{5}$/'];
    }

    /** "LDDAP Number must be in the format 00-00-00000." */
    public static function message(string $field): string
    {
        return "{$field} must be in the format 00-00-00000.";
    }
}
