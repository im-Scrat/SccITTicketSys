<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

enum EmbeddableSourceType: string
{
    use HasValues;

    case Ticket = 'ticket';
    case TicketComment = 'ticket_comment';
    case MaintenanceRecord = 'maintenance_record';
    case KnowledgeArticle = 'knowledge_article';
}
