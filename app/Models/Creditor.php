<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;

/** A creditor on file. See AccountHolder for the stamps. */
#[Fillable(['name', 'account_no', 'unit'])]
class Creditor extends AccountHolder
{
    protected $table = 'creditors';

    public static function label(): string
    {
        return 'creditor';
    }
}
