<?php

namespace App\Enums;

enum UserType: string
{
    case Admin = 'admin';
    case Supplier = 'supplier';
    case Client = 'client';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
