<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

enum UserStatus: string
{
    use HasValues;

    case Active = 'active';
    case Inactive = 'inactive';
    case Suspended = 'suspended';
    case Pending = 'pending';
}
