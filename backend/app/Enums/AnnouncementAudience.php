<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

enum AnnouncementAudience: string
{
    use HasValues;

    case All = 'all';
    case Teachers = 'teachers';
    case Technicians = 'technicians';
    case Admins = 'admins';
}
