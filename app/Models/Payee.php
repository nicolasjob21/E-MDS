<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A registered payee, with one or more bank accounts. Looked up by name or account number as an
 * LDDAP is registered; the chosen account's number and bank are copied onto the record.
 */
#[Fillable(['name'])]
class Payee extends Model
{
    public function accounts(): HasMany
    {
        return $this->hasMany(PayeeAccount::class)->orderBy('id');
    }

    public function lddaps(): HasMany
    {
        return $this->hasMany(Lddap::class);
    }

    /** Case-insensitive match on the name, or on any of the payee's account numbers. */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $like = '%'.strtolower(trim($term)).'%';

        return $query->where(function (Builder $q) use ($like) {
            $q->whereRaw('lower(name) like ?', [$like])
                ->orWhereHas('accounts', fn (Builder $a) => $a->whereRaw('lower(account_no) like ?', [$like]));
        });
    }
}
