<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
| Run by the dedicated `scheduler` container (`php artisan schedule:work`) in
| both the development and production stacks.
*/

/**
 * Close tickets left Resolved without a reporter's confirmation past the
 * configured window (SRS FR-TKT-016).
 *
 * Daily rather than hourly: the window is measured in days, so a finer cadence
 * would only move the closure a few hours earlier while running the sweep 24
 * times as often. `withoutOverlapping` guards against a slow run colliding with
 * the next, and `onOneServer` keeps it correct if the app is ever scaled to
 * multiple replicas (NFR-SCAL-003).
 */
Schedule::command('tickets:close-stale')
    ->dailyAt('02:15')
    ->withoutOverlapping()
    ->onOneServer();
