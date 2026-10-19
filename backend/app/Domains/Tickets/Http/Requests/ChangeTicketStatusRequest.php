<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Http\Requests;

use App\Models\Ticket;
use App\Models\TicketStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a lifecycle status change (SRS FR-TKT-005).
 *
 * Authorization here answers only *may this actor attempt a transition at all* —
 * whether **this particular** move is legal for **this particular** actor is
 * `TicketLifecycle`'s decision, and it answers 422 naming the reachable states.
 *
 * That split matters: "you have no business touching this ticket" is a 403,
 * while "you may act on this ticket, but In Progress cannot jump straight to
 * Closed" is a 422 about the workflow. Collapsing them would tell a technician
 * they lack permission when they simply picked the wrong next step.
 */
class ChangeTicketStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $ticket = $this->route('ticket');

        return $ticket instanceof Ticket && (bool) $this->user()?->can('transition', $ticket);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::exists('ticket_statuses', 'slug')],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.exists' => 'That is not a recognized ticket status.',
        ];
    }

    /** The validated target, resolved once for the controller. */
    public function target(): TicketStatus
    {
        return TicketStatus::query()->where('slug', $this->validated('status'))->firstOrFail();
    }
}
