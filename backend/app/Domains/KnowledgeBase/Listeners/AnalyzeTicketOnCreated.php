<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Listeners;

use App\Domains\KnowledgeBase\Jobs\AnalyzeTicketJob;
use App\Domains\Tickets\Events\TicketCreated;
use Illuminate\Support\Facades\DB;

/**
 * WP-I — attaches to the seam `TicketCreated` was raised for (see that
 * event's own docblock). `CreateTicket` itself is untouched: this listener is
 * the whole point of the seam existing.
 *
 * `DB::afterCommit()`, matching this codebase's established idiom for a
 * listener that pushes a real job (see e.g. `UpdateMaintenanceRecord`,
 * `SubmitWorkSupportRequest`) — this project's queue connections all run with
 * `after_commit => false` (config/queue.php), so a dispatch call here is not
 * automatically deferred; wrapping it explicitly is what the WP-I mandate's
 * "do not allow a queued worker to observe uncommitted records" actually
 * requires. `CreateTicket` already dispatches `TicketCreated` after its own
 * transaction commits, so in the common case this callback runs immediately —
 * the wrapping is what protects the case where `CreateTicket::handle()` is
 * ever called from inside a still-open outer transaction.
 */
class AnalyzeTicketOnCreated
{
    public function handle(TicketCreated $event): void
    {
        DB::afterCommit(fn () => AnalyzeTicketJob::dispatch($event->ticket));
    }
}
