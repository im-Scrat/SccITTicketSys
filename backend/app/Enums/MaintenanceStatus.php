<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

enum MaintenanceStatus: string
{
    use HasValues;

    case Scheduled = 'scheduled';
    case InProgress = 'in_progress';
    case OnHold = 'on_hold';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /**
     * Presentation tone for the status badge, returned by the API so the client
     * never hard-codes a status hue (the `AssetStatus::tone()` convention).
     * Lives on the enum so every surface that shows a maintenance status — the
     * Maintenance module's own resources and, since WP-K, the repair section of
     * a technician's ticket — colours it from one place.
     *
     * @return 'neutral'|'info'|'success'|'warning'|'danger'
     */
    public function tone(): string
    {
        return match ($this) {
            self::Completed => 'success',
            self::InProgress => 'info',
            self::OnHold => 'warning',
            self::Cancelled => 'danger',
            self::Scheduled => 'neutral',
        };
    }
}
