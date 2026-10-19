<?php

declare(strict_types=1);

namespace App\Domains\Maintenance\Rules;

use App\Models\Ticket;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A maintenance record may be linked only to a ticket its author could open in
 * full — `TicketPolicy::viewFull()`, i.e. `TicketVisibility::canSeeFull()`: an
 * administrator, the technician assigned to it, or its reporter.
 *
 * Before WP-K the link was checked with `Rule::exists` alone, so any technician
 * could attach their record to **any** ticket uuid — and the record's own
 * response then printed that ticket's number and title, handing them a ticket
 * `TicketVisibility` denies. It also let false links into the repair history
 * that predictive maintenance (WP-L) reads through `maintenance_records.ticket_id`.
 *
 * One message for "no such ticket" and "not a ticket you may link", the same
 * stance `SubmitProofOfWork` takes: telling them apart would turn a create
 * form into an existence oracle for ticket uuids.
 */
final class LinkableTicket implements ValidationRule
{
    /**
     * @param  string|null  $currentUuid  the record's existing link, on an edit —
     *                                    re-sending it unchanged is not a new link,
     *                                    even if an administrator made it to a ticket
     *                                    this editor could not link themselves
     */
    public function __construct(
        private readonly ?User $user,
        private readonly ?string $currentUuid = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '' || $value === $this->currentUuid) {
            return;
        }

        $ticket = is_string($value)
            ? Ticket::query()->where('uuid', $value)->first()
            : null;

        if ($ticket === null || $this->user === null || ! $this->user->can('viewFull', $ticket)) {
            $fail('That ticket is not one you can link this maintenance to.');
        }
    }
}
