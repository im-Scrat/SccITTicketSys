<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

enum InstallationStatus: string
{
    use HasValues;

    case Installed = 'installed';
    case Removed = 'removed';
    case Faulty = 'faulty';
}
