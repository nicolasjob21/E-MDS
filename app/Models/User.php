<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'username', 'email', 'password', 'role', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
        ];
    }

    /** Admin powers — an Admin or a Super Admin. */
    public function isAdmin(): bool
    {
        return $this->role?->isAdmin() === true;
    }

    /** The only role that may grant, change or remove the Super Admin role. */
    public function isSuperAdmin(): bool
    {
        return $this->role === UserRole::SuperAdmin;
    }

    /**
     * Active administrators — the recipients of workflow notifications.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeActiveAdmins($query)
    {
        return $query->whereIn('role', [UserRole::Admin, UserRole::SuperAdmin])->where('is_active', true);
    }

    public function isTeller(): bool
    {
        return $this->role === UserRole::Teller;
    }
}
