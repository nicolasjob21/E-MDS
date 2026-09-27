<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * A name paid into an account on file: the shape shared by the creditor and PCG personnel lists.
 * The account number is text so leading zeros are kept. `created_at` (Date Created) and
 * `created_by` (Added By) are stamped on creation and never change afterwards.
 */
abstract class AccountHolder extends Model
{
    /** What one entry is called in messages and the audit log: "creditor", "PCG personnel". */
    abstract public static function label(): string;

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            $model->created_by ??= Auth::id();
        });

        static::updating(function (self $model) {
            $model->created_by = $model->getOriginal('created_by');
            $model->created_at = $model->getOriginal('created_at');
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Case-insensitive match on the name, account number or unit. */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $like = '%'.strtolower(trim($term)).'%';

        return $query->where(function (Builder $q) use ($like) {
            $q->whereRaw('lower(name) like ?', [$like])
                ->orWhereRaw('lower(account_no) like ?', [$like])
                ->orWhereRaw('lower(unit) like ?', [$like]);
        });
    }
}
