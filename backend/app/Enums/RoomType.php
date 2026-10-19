<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

enum RoomType: string
{
    use HasValues;

    case Laboratory = 'laboratory';
    case Office = 'office';
    case Storage = 'storage';
    case ServerRoom = 'server_room';
    case FacultyRoom = 'faculty_room';
    case Library = 'library';
    case Other = 'other';

    /**
     * Sentence-case label for option lists and table cells (DESIGN.md: labels
     * are sentence case). Overrides the trait's word-cased default so
     * `server_room` reads "Server room", not "Server Room".
     */
    public function label(): string
    {
        return match ($this) {
            self::Laboratory => 'Laboratory',
            self::Office => 'Office',
            self::Storage => 'Storage',
            self::ServerRoom => 'Server room',
            self::FacultyRoom => 'Faculty room',
            self::Library => 'Library',
            self::Other => 'Other',
        };
    }
}
