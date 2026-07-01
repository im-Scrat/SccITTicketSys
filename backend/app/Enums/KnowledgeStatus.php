<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

enum KnowledgeStatus: string
{
    use HasValues;

    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';
}
