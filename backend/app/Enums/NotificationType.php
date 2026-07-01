<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

enum NotificationType: string
{
    use HasValues;

    case Info = 'info';
    case Success = 'success';
    case Warning = 'warning';
    case Error = 'error';
    case TicketUpdate = 'ticket_update';
    case Assignment = 'assignment';
    case Announcement = 'announcement';
    case Maintenance = 'maintenance';
    case System = 'system';
}
