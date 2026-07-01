<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

enum BackupType: string
{
    use HasValues;

    case Full = 'full';
    case Incremental = 'incremental';
    case Differential = 'differential';
}
