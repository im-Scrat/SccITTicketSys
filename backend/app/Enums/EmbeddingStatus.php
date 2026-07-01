<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

enum EmbeddingStatus: string
{
    use HasValues;

    case Pending = 'pending';
    case Processing = 'processing';
    case Indexed = 'indexed';
    case Failed = 'failed';
    case Stale = 'stale';
}
