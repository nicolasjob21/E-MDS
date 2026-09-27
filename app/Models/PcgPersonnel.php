<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;

/** A PCG personnel entry on file. See AccountHolder for the stamps. */
#[Fillable(['name', 'account_no', 'unit'])]
class PcgPersonnel extends AccountHolder
{
    protected $table = 'pcg_personnel';

    public static function label(): string
    {
        return 'PCG personnel';
    }
}
