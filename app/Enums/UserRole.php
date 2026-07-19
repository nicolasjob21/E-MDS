<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Staff = 'staff';
    case Teller = 'teller';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::Staff => 'Staff',
            self::Teller => 'Teller',
        };
    }
}
