<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

enum PermissionGrantType: string
{
    use HasValues;

    case Grant = 'grant';
    case Deny = 'deny';
}
