<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

/**
 * The lifecycle of a technician work support request (SRS FR-WSR-004;
 * SDD DD-54; Client decision [SRS OI-11], 2026-08-28).
 *
 * **Exactly six states, and the client may never set one.** Every move is a
 * named, separately-authorized operation on `WorkSupportRequestLifecycle`, not a
 * `PATCH` of this column — the `TicketLifecycle`/`MaintenanceLifecycle` stance
 * (DD-43, DD-55), for the reason DD-54 gives: when a status is settable, the one
 * transition nobody validates is the one that eventually corrupts the record.
 *
 * `under_review` was deliberately **not** adopted (OI-11): it would need a
 * claim/release mechanic to stay truthful, and "pending" versus "needs review"
 * is one state seen through two filters, not two states.
 *
 * `cancelled` was added at the Client's instruction so a technician may withdraw
 * a request that is no longer needed ([FR-WSR-014](#)) — bounded to requests not
 * yet decided, and terminal, so a withdrawal cannot be quietly reversed after
 * the fact.
 */
enum WorkSupportStatus: string
{
    use HasValues;

    /** Submitted by the technician, awaiting an Administrator decision. */
    case Submitted = 'submitted';

    /**
     * The Administrator has asked for a face-to-face explanation. Not a
     * decision — the request returns to `approved` or `declined` afterwards.
     */
    case ClarificationRequested = 'clarification_requested';

    /** Approved, with a new schedule for the work (FR-WSR-006). */
    case Approved = 'approved';

    /** Declined, with a reason the technician can read (FR-WSR-008). */
    case Declined = 'declined';

    /** Withdrawn by the submitting technician before any decision. Terminal. */
    case Cancelled = 'cancelled';

    /** The request is finished, or its job completed. Terminal. */
    case Closed = 'closed';

    /**
     * States from which an Administrator decision is still outstanding.
     *
     * This is the set a technician may cancel from (FR-WSR-014) and the set the
     * administrator inbox reads as "needs attention". Kept here rather than in
     * the lifecycle so the queries and the transition map cannot drift into two
     * different definitions of "undecided".
     *
     * @return list<self>
     */
    public static function undecided(): array
    {
        return [self::Submitted, self::ClarificationRequested];
    }

    /**
     * States nothing moves out of.
     *
     * `cancelled` is a withdrawal and `closed` is a conclusion; neither is a
     * state work resumes from. A further need is a further request.
     *
     * @return list<self>
     */
    public static function terminal(): array
    {
        return [self::Cancelled, self::Closed];
    }

    /** Is an Administrator decision still outstanding on this request? */
    public function isUndecided(): bool
    {
        return in_array($this, self::undecided(), true);
    }

    /** Is this a state nothing moves out of? */
    public function isTerminal(): bool
    {
        return in_array($this, self::terminal(), true);
    }

    /**
     * Human label. Overridden for the two the default would render badly:
     * `Clarification Requested` reads as jargon on a technician's tracking page,
     * and the workflow language the Client used is "face-to-face".
     */
    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Submitted',
            self::ClarificationRequested => 'Face-to-face requested',
            self::Approved => 'Approved',
            self::Declined => 'Declined',
            self::Cancelled => 'Cancelled',
            self::Closed => 'Closed',
        };
    }
}
