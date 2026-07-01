<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

enum AiSender: string
{
    use HasValues;

    case User = 'user';
    case Assistant = 'assistant';
    case System = 'system';
}
