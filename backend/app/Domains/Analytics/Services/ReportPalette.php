<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Services;

/**
 * Chart colours for documents (Phase 2.10, SDD DD-84/DD-91).
 *
 * The light-theme values of the screen's chart tokens (`--chart-status-*` in
 * `frontend/src/index.css`, validated with the dataviz checker: every mark at
 * least 3:1 on white, worst adjacent colour-blind ΔE 8.7). A document is
 * always light — it is printed on paper — so only the light values apply. A
 * test holds these equal to the CSS so the two cannot drift.
 */
final class ReportPalette
{
    public const CURRENT = '#1d3f8f';

    public const COMPARE = '#6b6f76';

    public const BAR = '#1d3f8f';

    public const GRID = '#d9d6cf';

    public const AXIS = '#444444';

    /** @var array<string, string> */
    public const STATUS = [
        'open' => '#0b67a9',
        'assigned' => '#00a0a1',
        'in-progress' => '#2e4d99',
        'on-hold' => '#c08500',
        'resolved' => '#00814a',
        'closed' => '#4c5057',
        'cancelled' => '#85898f',
    ];

    public const STATUS_FALLBACK = '#6b6f76';

    public static function status(string $slug): string
    {
        return self::STATUS[$slug] ?? self::STATUS_FALLBACK;
    }
}
