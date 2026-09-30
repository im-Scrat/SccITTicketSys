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

/**
 * Report preventive maintenance that is overdue or falls due inside the
 * configured reminder window (SRS FR-MNT-007).
 *
 * Detection only. It creates no maintenance record — FR-MNT-002 has a person
 * open a preventive visit with a date — and it dispatches no notification,
 * because the project channel driver (SDD DD-52) is WP-2.6b's minimum
 * notification work. The same predicate feeds `/api/maintenance/scheduled` and
 * the module dashboard, so this run and those screens can never disagree about
 * what is overdue.
 *
 * Daily, ahead of the working day: the lead time is measured in days, so an
 * hourly cadence would re-report the same rows 24 times as often.
 */
Schedule::command('maintenance:detect-due')
    ->dailyAt('06:30')
    ->withoutOverlapping()
    ->onOneServer();

/**
 * Reconcile the knowledge-article vector index with the articles (WP-P,
 * SRS FR-AI-006) — the safety net behind the model observer, for articles that
 * pre-date the index, edits made while the queue or provider was down, workers
 * that died mid-run and chunker upgrades.
 *
 * Daily and off-hours: it reads state and hashes only and never calls the
 * provider itself, but it may queue embedding work, and that belongs outside
 * the school day. It is idempotent, so a missed run is simply caught up by the
 * next one.
 */
Schedule::command('knowledge:index')
    ->dailyAt('03:10')
    ->withoutOverlapping()
    ->onOneServer();
