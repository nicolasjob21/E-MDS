<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An office unit an LDDAP is drawn for. Offered as a select when registering one. */
#[Fillable(['name'])]
class Unit extends Model
{
    public function lddaps(): HasMany
    {
        return $this->hasMany(Lddap::class);
    }
}
