<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

enum ScanResult: string
{
    use HasValues;

    case Success = 'success';
    case Invalid = 'invalid';
    case Expired = 'expired';
    case Mismatch = 'mismatch';
}
