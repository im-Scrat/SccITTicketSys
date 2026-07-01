<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

enum AiEventType: string
{
    use HasValues;

    case TicketResolved = 'ticket_resolved';
    case MaintenanceCompleted = 'maintenance_completed';
    case PatternDetected = 'pattern_detected';
    case ArticleCreated = 'article_created';
    case FeedbackReceived = 'feedback_received';
}
