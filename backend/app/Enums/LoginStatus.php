<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

enum LoginStatus: string
{
    use HasValues;

    case Success = 'success';
    case Failed = 'failed';
    case LockedOut = 'locked_out';
}
