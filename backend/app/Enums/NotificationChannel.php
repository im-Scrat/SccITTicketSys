<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

enum NotificationChannel: string
{
    use HasValues;

    case InApp = 'in_app';
    case Email = 'email';
}
