<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

/**
 * The administrator's decision for one location text (Phase 2.10 R6).
 * `unassigned` is always an explicit choice: rows are never left without a
 * room by default.
 */
enum ImportLocationResolution: string
{
    use HasValues;

    case Pending = 'pending';
    case Room = 'room';
    case Unassigned = 'unassigned';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Not decided',
            self::Room => 'Room chosen',
            self::Unassigned => 'Leave without a room',
        };
    }
}
