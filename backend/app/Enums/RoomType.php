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
}
