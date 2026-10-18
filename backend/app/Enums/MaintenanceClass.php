<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

/**
 * How a completed maintenance record is counted in reports (Phase 2.10 R4,
 * SDD DD-94): **preventive** when its type is preventive, **upgrade** for the
 * `hardware-upgrade` type, **corrective** for everything else — a repair of
 * something that failed. An unknown future type is therefore corrective, the
 * conservative reading for failure figures (plan assumption A15).
 */
enum MaintenanceClass: string
{
    use HasValues;

    case Corrective = 'corrective';
    case Preventive = 'preventive';
    case Upgrade = 'upgrade';

    public const UPGRADE_TYPE_SLUG = 'hardware-upgrade';

    public function label(): string
    {
        return match ($this) {
            self::Corrective => 'Corrective repairs',
            self::Preventive => 'Preventive visits',
            self::Upgrade => 'Upgrades',
        };
    }

    /** The SQL CASE that classifies `maintenance_types as mt`, for GROUP BY and filters. */
    public static function sqlCase(string $alias = 'mt'): string
    {
        return "CASE WHEN {$alias}.is_preventive THEN 'preventive' WHEN {$alias}.slug = '".self::UPGRADE_TYPE_SLUG."' THEN 'upgrade' ELSE 'corrective' END";
    }
}
