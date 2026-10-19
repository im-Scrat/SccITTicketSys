<?php

declare(strict_types=1);

namespace App\Domains\Maintenance\Events;

use App\Models\MaintenanceRecord;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A maintenance record reached `completed` — the moment a repair becomes
 * history (SRS FR-MNT-003/008). WP-K raises it; WP-L's learning pipeline
 * (`ai_learning_events` → `AnalyzePcHistoryJob`) is its first consumer.
 *
 * **After commit, by contract, not by placement.** `ShouldDispatchAfterCommit`
 * makes the installed dispatcher (Laravel 13.17, `Events\Dispatcher::dispatch`)
 * hand this event to the transaction manager instead of its listeners; the
 * manager runs it only when the **root** transaction commits, and discards it
 * on rollback. That matters because completion is reachable nested:
 * `SubmitProofOfWork` wraps `MaintenanceLifecycle::transition()` in its own
 * transaction, so the lifecycle's inner `DB::transaction()` is only a savepoint
 * — "after the inner block returns" is still inside an uncommitted write.
 * Dispatching from there without this contract would let a listener (or a
 * queued job it starts) read a record that may yet roll back.
 *
 * Carries the record, not a snapshot of it: `SerializesModels` re-reads it by
 * key when a queued listener runs, which after commit is the committed row.
 */
class MaintenanceCompleted implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly MaintenanceRecord $record) {}
}
