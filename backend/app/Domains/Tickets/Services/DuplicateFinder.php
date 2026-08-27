<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Services;

use App\Models\PcUnit;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Finds tickets that may already describe the fault a requester is about to
 * report (SRS FR-TKT-011/014, UCS-02 step 1).
 *
 * **This is text similarity, not AI.** It ranks the existing `tsvector` with
 * `ts_rank` and boosts matches on the same machine — nothing more. The AI
 * duplicate suggestion of FR-AI-009 is a later phase, and the UI labels this
 * for what it is rather than dressing it up as analysis. A honest "similar
 * reports" list is more useful than a fake confident one.
 *
 * The strongest signal is not the wording at all: **the same PC unit with an
 * open ticket** is almost certainly the same fault, whatever words the second
 * reporter chose. So those are returned first, regardless of text rank.
 */
class DuplicateFinder
{
    /** Enough to recognise a match; not so many that the reporter scrolls. */
    private const LIMIT = 5;

    /*
     * No `TicketVisibility` dependency: the endpoint is gated on
     * `tickets.create`, which only Teachers and Administrators hold, and both
     * may see any open ticket as a community card anyway. Every result is
     * rendered through `TicketFeedResource`, so the redaction is applied by the
     * projection rather than by a scope this class would duplicate.
     */

    /**
     * Candidate duplicates for a fault being described.
     *
     * Only **open** tickets are offered: a resolved report of the same problem
     * is history, and pointing a reporter at it would tell them to upvote
     * something already dealt with.
     *
     * @return Collection<int, Ticket>
     */
    public function search(?string $text, ?PcUnit $pcUnit, ?Ticket $exclude = null): Collection
    {
        $text = $text !== null ? trim($text) : '';

        if ($text === '' && $pcUnit === null) {
            return new Collection;
        }

        $query = Ticket::query()
            ->with([
                'status', 'priority', 'category',
                'reporter:id,uuid,first_name,last_name',
                'pcUnit:id,uuid,unit_code,pc_name,room_id',
                'pcUnit.room:id,uuid,name,floor_id',
                'pcUnit.room.floor:id,uuid,name,building_id',
                'pcUnit.room.floor.building:id,uuid,name',
            ])
            ->whereHas('status', fn (Builder $q): Builder => $q->where('is_open', true))
            // A ticket already flagged as a duplicate is not the canonical one
            // to point someone at.
            ->whereNull('duplicate_of_id');

        if ($exclude !== null) {
            $query->whereKeyNot($exclude->getKey());
        }

        $query->where(function (Builder $outer) use ($text, $pcUnit): void {
            if ($pcUnit !== null) {
                $outer->orWhere('tickets.pc_unit_id', $pcUnit->getKey());
            }

            if ($text !== '') {
                $outer->orWhereRaw("search_vector @@ plainto_tsquery('english', ?)", [$text]);
            }
        });

        // Same machine first, then text relevance, then most recent. The
        // `ts_rank` expression is only meaningful when there is a query to rank
        // against, so it degrades to zero for a pure PC-unit lookup.
        $query->orderByRaw(
            'CASE WHEN tickets.pc_unit_id IS NOT DISTINCT FROM ? THEN 0 ELSE 1 END',
            [$pcUnit?->getKey()],
        );

        if ($text !== '') {
            $query->orderByRaw("ts_rank(search_vector, plainto_tsquery('english', ?)) DESC", [$text]);
        }

        return $query->orderByDesc('tickets.created_at')->limit(self::LIMIT)->get();
    }
}
