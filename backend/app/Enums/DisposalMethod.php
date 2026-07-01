<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

enum DisposalMethod: string
{
    use HasValues;

    case Recycled = 'recycled';
    case Sold = 'sold';
    case Donated = 'donated';
    case Destroyed = 'destroyed';
    case Returned = 'returned';
    case Lost = 'lost';
}
