<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

enum AiModality: string
{
    use HasValues;

    case Text = 'text';
    case Embedding = 'embedding';
    case Multimodal = 'multimodal';
}
