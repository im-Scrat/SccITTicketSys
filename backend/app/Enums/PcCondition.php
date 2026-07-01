<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

enum PcCondition: string
{
    use HasValues;

    case Working = 'working';
    case Faulty = 'faulty';
    case ForRepair = 'for_repair';
    case Decommissioned = 'decommissioned';
}
