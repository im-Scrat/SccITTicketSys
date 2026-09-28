<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

/**
 * PC operational status. Values align to the Interactive Floor Plan icon colors.
 */
enum PcStatus: string
{
    use HasValues;

    case Available = 'available';
    case Assigned = 'assigned';
    case Online = 'online';
    case Offline = 'offline';
    case UnderMaintenance = 'under_maintenance';
    case Retired = 'retired';

    /**
     * Explicit rather than the `HasValues` default, so wording is a decision
     * made here and not a side effect of a backing value. The strings are
     * identical to what the default derived, so existing consumers (the
     * analytics status mix) are unchanged.
     */
    public function label(): string
    {
        return match ($this) {
            self::Available => 'Available',
            self::Assigned => 'Assigned',
            self::Online => 'Online',
            self::Offline => 'Offline',
            self::UnderMaintenance => 'Under Maintenance',
            self::Retired => 'Retired',
        };
    }

    /**
     * Presentation tone, returned by the API so the client never hard-codes a
     * status colour (the {@see AssetStatus::tone()} convention). The floor plan
     * must not rely on colour alone (FR-FP-004): the client pairs this with the
     * label and a shape.
     *
     * Retired is `neutral`, not `danger`: a decommissioned machine is an
     * expected end state, not a fault, and should not draw the eye on a map.
     *
     * @return 'neutral'|'success'|'warning'|'danger'
     */
    public function tone(): string
    {
        return match ($this) {
            self::Online => 'success',
            self::UnderMaintenance => 'warning',
            self::Offline => 'danger',
            self::Available, self::Assigned, self::Retired => 'neutral',
        };
    }
}
