<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Services;

use App\Domains\Analytics\Events\AdminDashboardChanged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2.9 — the one way to say "the dashboard picture changed".
 *
 * Two effects, both deferred until the surrounding transaction commits:
 *
 *  1. bump the dashboard cache **version**. {@see DashboardService} folds it
 *     into every cache key, so the refetch a realtime signal triggers is built
 *     fresh instead of being answered from a 30-second-old payload;
 *  2. broadcast {@see AdminDashboardChanged} to the administrators' channel.
 *
 * Nothing about the change itself is passed on beyond its kind and uuid.
 */
class AdminDashboardSignal
{
    private const VERSION_KEY = 'dashboard:version';

    public function send(string $kind, ?string $subject = null): void
    {
        // A no-op outside a transaction (runs now); inside one, it waits for
        // the commit and is discarded on rollback — the bump never runs ahead
        // of the data it describes.
        DB::afterCommit(function (): void {
            Cache::increment(self::VERSION_KEY);
        });

        AdminDashboardChanged::dispatch($kind, $subject);
    }

    /** The current dashboard cache version, for {@see DashboardService::cacheKey()}. */
    public function version(): int
    {
        return (int) Cache::get(self::VERSION_KEY, 0);
    }
}
