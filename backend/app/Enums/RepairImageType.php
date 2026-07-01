<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

enum RepairImageType: string
{
    use HasValues;

    case Before = 'before';
    case During = 'during';
    case After = 'after';
}
