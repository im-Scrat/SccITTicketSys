<?php

declare(strict_types=1);

namespace App\Domains\FloorPlan\Services;

use App\Models\SystemSetting;

/**
 * The two seeded `floor_plan.*` settings (SystemSettingSeeder), read where the
 * floor plan needs them.
 *
 * Not a settings layer — the project has none, and this phase does not build
 * one (reconnaissance decision D4). It is the same ad-hoc `system_settings`
 * read `TicketLifecycle`, `QrService` and `MaintenanceMetrics` each carry,
 * kept in one place for this domain so the map and the editor cannot disagree
 * about the default. `room_layouts.grid_size` stays the authority for an
 * existing layout; the grid-size setting only seeds a new one.
 *
 * Note that `value()` on an Eloquent builder applies the model's `array` cast,
 * so a stored JSON `false` arrives as the boolean `false` — not the string
 * `"false"`, and not something `(string)` can be trusted to render (it gives
 * `""`). Each reader below interprets the decoded value directly.
 */
class FloorPlanDefaults
{
    /** Absent or unreadable means snap: the grid is the safe default. */
    public function snapToGrid(): bool
    {
        $value = $this->setting('floor_plan.snap_to_grid');

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_string($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true;
        }

        return true;
    }

    /** The grid a new layout starts with; 20 px when unset or invalid. */
    public function gridSize(): int
    {
        $value = $this->setting('floor_plan.default_grid_size');
        $size = is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : 20;

        return $size >= 1 ? $size : 20;
    }

    private function setting(string $key): mixed
    {
        return SystemSetting::query()->where('key', $key)->value('value');
    }
}
