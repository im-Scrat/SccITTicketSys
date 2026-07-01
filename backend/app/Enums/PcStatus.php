<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

/**
 * PC operational status. Values align to the Interactive Floor Plan icon colors.
 */
enum PcStatus: string
{
    use HasValues;

    case Available = 'available';
    case Assigned = 'assigned';
    case Online = 'online';
    case Offline = 'offline';
    case UnderMaintenance = 'under_maintenance';
    case Retired = 'retired';
}
