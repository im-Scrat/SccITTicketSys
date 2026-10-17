<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

/**
 * Phase 2.9 — *why* a ticket has the priority it has (`tickets.priority_source`).
 *
 * The order of authority is deliberate (owner decision D-B, extended by SDD
 * DD-75 when reporters were allowed to choose), weakest claim first:
 *
 *   category  <  reporter  <  location floor  <  AI  <  manual
 *
 *  - a category default is the weakest claim;
 *  - a reporter's own choice outranks it — they are standing in front of the
 *    fault — but is still only a claim;
 *  - an administrator-configured room floor raises either (never lowers);
 *  - the AI may only ever *raise* above all three;
 *  - an explicit choice by IT staff is the only lock: the floor does not apply
 *    to it at creation and the AI never changes it afterwards.
 *
 * NULL on a row means it predates this column — unknown, not "category" — and
 * is raisable by the AI like any non-manual source.
 *
 * The DB CHECK is **not** built from {@see values()} on an installed database:
 * each widening is its own migration with frozen lists (see
 * `2026_10_16_100000_add_reporter_to_ticket_priority_source`). A new case
 * needs a NEW migration, or production refuses it.
 */
enum TicketPrioritySource: string
{
    use HasValues;

    /** The category's default (or the medium fallback). */
    case Category = 'category';

    /**
     * Chosen by the person reporting the fault, who is not IT staff. Second
     * weakest: a room floor and the AI may both raise it.
     */
    case Reporter = 'reporter';

    /** Raised to the room's administrator-configured priority floor at creation. */
    case Location = 'location';

    /** Raised by an AI recommendation that passed every deterministic check. */
    case Ai = 'ai';

    /**
     * Chosen explicitly by IT staff (an administrator or technician). The only
     * lock: the AI never changes a manual priority.
     */
    case Manual = 'manual';
}
