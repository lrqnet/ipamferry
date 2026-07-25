<?php

namespace App\Enums;

enum UserRole: string
{
    case Owner = 'owner';
    case Administrator = 'administrator';
    case Operator = 'operator';
    case Reader = 'reader';

    public function canOperate(): bool
    {
        return $this !== self::Reader;
    }
}
