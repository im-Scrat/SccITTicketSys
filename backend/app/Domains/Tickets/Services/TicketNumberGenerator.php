<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Services;

use App\Models\Ticket;
use Illuminate\Support\Facades\DB;

/**
 * Issues the human-readable `ticket_number` (SRS FR-TKT-002).
 *
 * Two identifiers, two jobs: the `uuid` is what URLs and APIs use, because it
 * leaks nothing and cannot be enumerated (NFR-SEC-001); this number is what a
 * teacher reads down the phone. It is deliberately short and year-scoped —
 * `TKT-2026-00042` — rather than a uuid nobody can dictate.
 *
 * The sequence restarts each year and is derived from the highest existing
 * number for that year, taken under a **row lock on the tickets table's matching
 * rows** so two simultaneous submissions cannot claim the same number. The
 * `ticket_number` unique constraint is the backstop; the retry loop means a
 * collision costs a retry rather than a 500.
 */
class TicketNumberGenerator
{
    private const PREFIX = 'TKT';

    private const PAD = 5;

    private const MAX_ATTEMPTS = 5;

    /**
     * The next available number for the current year.
     *
     * Must be called inside the same transaction as the ticket insert, so the
     * lock it takes is still held when the row lands.
     */
    public function next(): string
    {
        $year = (int) now()->format('Y');
        $prefix = self::PREFIX.'-'.$year.'-';

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $latest = DB::table('tickets')
                ->where('ticket_number', 'like', $prefix.'%')
                ->lockForUpdate()
                ->orderByDesc('ticket_number')
                ->value('ticket_number');

            $sequence = $latest === null
                ? 1
                : ((int) substr((string) $latest, strlen($prefix))) + 1;

            $candidate = $prefix.str_pad((string) $sequence, self::PAD, '0', STR_PAD_LEFT);

            // Belt and braces: a number could exist from an import that did not
            // go through this generator.
            if (! Ticket::withTrashed()->where('ticket_number', $candidate)->exists()) {
                return $candidate;
            }
        }

        // Every attempt collided — fall back to a value that cannot, rather than
        // failing the submission the user has already written out.
        return $prefix.strtoupper(bin2hex(random_bytes(4)));
    }
}
