<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

/**
 * Room fixtures a Floorplan can hold (Phase 2.11, SDD DD-102). Layout-only
 * shapes — they carry no asset identity and are never confused with the PCs
 * placed on the same plan.
 */
enum FloorPlanFixtureKind: string
{
    use HasValues;

    case Wall = 'wall';
    case Partition = 'partition';
    case Door = 'door';
    case Window = 'window';
    case Desk = 'desk';
    case Cabinet = 'cabinet';
    case Pillar = 'pillar';
    case Zone = 'zone';

    public function label(): string
    {
        return match ($this) {
            self::Wall => 'Wall',
            self::Partition => 'Partition',
            self::Door => 'Door',
            self::Window => 'Window',
            self::Desk => 'Desk',
            self::Cabinet => 'Cabinet',
            self::Pillar => 'Pillar',
            self::Zone => 'Zone',
        };
    }

    /**
     * Draw order, lowest first: a zone is a floor area under everything, desks
     * and cabinets stand on it, walls bound it, doors and windows sit in the
     * walls. Workstations are always drawn above every fixture.
     */
    public function layer(): int
    {
        return match ($this) {
            self::Zone => 0,
            self::Desk, self::Cabinet => 1,
            self::Wall, self::Partition, self::Pillar => 2,
            self::Door, self::Window => 3,
        };
    }
}
