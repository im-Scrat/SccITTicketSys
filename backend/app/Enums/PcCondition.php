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

    /**
     * The conditions the add-asset form offers (owner request 2026-10-20, SDD
     * DD-114): Working or For repair. The enum itself is unchanged — PC units
     * and maintenance records share it, and assets recorded earlier as
     * "faulty" or "decommissioned" keep that value until edited.
     *
     * @return list<string>
     */
    public static function assetFormValues(): array
    {
        return [self::Working->value, self::ForRepair->value];
    }
}
