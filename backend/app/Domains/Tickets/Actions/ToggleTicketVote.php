<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Actions;

use App\Models\Ticket;
use App\Models\TicketVote;
use App\Models\User;
use Illuminate\Database\QueryException;

/**
 * Add or remove an upvote (SRS FR-TKT-009/010).
 *
 * Three things this class deliberately does **not** do:
 *
 *  1. **It never writes `tickets.upvote_count`.** An `AFTER INSERT OR DELETE`
 *     trigger owns that column, which is what makes the count correct under
 *     concurrency — two simultaneous votes each fire the trigger, whereas a
 *     read-modify-write from PHP would lose one.
 *  2. **It does not check for an existing vote before inserting.** That check
 *     would be a race: two tabs can both pass it. The `UNIQUE(ticket_id,
 *     user_id)` index is the real guarantee, so the insert is simply attempted
 *     and a unique violation is treated as "already voted" — the outcome the
 *     user wanted anyway.
 *  3. **It is not audited.** A vote is a requester signal, not an administrative
 *     act; logging 51 of them per ticket would bury the trail that matters.
 */
class ToggleTicketVote
{
    /**
     * @return array{voted: bool, upvote_count: int}
     */
    public function handle(Ticket $ticket, User $user): array
    {
        $existing = TicketVote::query()
            ->where('ticket_id', $ticket->getKey())
            ->where('user_id', $user->getKey())
            ->first();

        if ($existing !== null) {
            $existing->delete();

            return $this->result($ticket, voted: false);
        }

        try {
            TicketVote::query()->create([
                'ticket_id' => $ticket->getKey(),
                'user_id' => $user->getKey(),
                'created_at' => now(),
            ]);
        } catch (QueryException $exception) {
            // Lost the race with another tab or a double click. The user's
            // intent — "my vote is on this ticket" — is already satisfied, so
            // report success rather than an error they cannot act on.
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }
        }

        return $this->result($ticket, voted: true);
    }

    /**
     * Read the count back from the database rather than computing it, because
     * the trigger is what set it and only the database knows the value after
     * concurrent writes.
     *
     * @return array{voted: bool, upvote_count: int}
     */
    private function result(Ticket $ticket, bool $voted): array
    {
        return [
            'voted' => $voted,
            'upvote_count' => (int) $ticket->refresh()->upvote_count,
        ];
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return $exception->getCode() === '23505';
    }
}
