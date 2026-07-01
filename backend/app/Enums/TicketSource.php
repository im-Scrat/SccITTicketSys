<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

enum TicketSource: string
{
    use HasValues;

    case Web = 'web';
    case Mobile = 'mobile';
    case Email = 'email';
    case Phone = 'phone';
    case WalkIn = 'walk_in';
    case Ai = 'ai';
}
