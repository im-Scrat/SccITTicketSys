<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

enum AssetStatus: string
{
    use HasValues;

    case InStock = 'in_stock';
    case Deployed = 'deployed';
    case InRepair = 'in_repair';
    case Reserved = 'reserved';
    case InTransit = 'in_transit';
    case Retired = 'retired';
    case Disposed = 'disposed';
}
