<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

/**
 * Phase 2.9 — *why* a ticket has the priority it has (`tickets.priority_source`).
 *
 * The order of authority is deliberate (owner decision D-B): a category default
 * is the weakest claim, an administrator-configured room floor overrides it,
 * the AI may only ever *raise* above either, and a human's explicit choice
 * locks the ticket against the AI entirely. NULL on a row means it predates
 * this column — unknown, not "category".
 */
enum TicketPrioritySource: string
{
    use HasValues;

    /** The category's default (or the medium fallback). */
    case Category = 'category';

    /** Raised to the room's administrator-configured priority floor at creation. */
    case Location = 'location';

    /** Raised by an AI recommendation that passed every deterministic check. */
    case Ai = 'ai';

    /** Chosen explicitly by staff. The AI never changes a manual priority. */
    case Manual = 'manual';
}
