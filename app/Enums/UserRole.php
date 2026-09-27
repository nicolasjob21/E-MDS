<?php

namespace App\Enums;

enum UserRole: string
{
    /**
     * An admin who also controls the role itself: only a Super Admin can grant, change or remove
     * it. Otherwise the same as an admin — including checking cheque drafts (Approve / Return).
     */
    case SuperAdmin = 'super_admin';
    case Admin = 'admin';
    case Staff = 'staff';
    case Teller = 'teller';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::Admin => 'Administrator',
            self::Staff => 'Staff',
            self::Teller => 'Teller',
        };
    }

    /** Admin powers: Admin and Super Admin alike. */
    public function isAdmin(): bool
    {
        return $this === self::Admin || $this === self::SuperAdmin;
    }
}
