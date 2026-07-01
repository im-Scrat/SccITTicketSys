<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

enum TicketUpdateType: string
{
    use HasValues;

    case Comment = 'comment';
    case StatusChange = 'status_change';
    case PriorityChange = 'priority_change';
    case Assignment = 'assignment';
    case AiAnalysis = 'ai_analysis';
    case System = 'system';
}
