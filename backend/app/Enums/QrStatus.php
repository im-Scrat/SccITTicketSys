<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

enum QrStatus: string
{
    use HasValues;

    case Active = 'active';
    case Inactive = 'inactive';
    case Revoked = 'revoked';
}
