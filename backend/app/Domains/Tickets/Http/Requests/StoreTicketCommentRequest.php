<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Http\Requests;

use App\Models\Ticket;
use App\Models\TicketComment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a new comment (SRS FR-TKT-007).
 *
 * `is_internal` is **prohibited unless the caller may post internal notes** —
 * not silently coerced to false. A requester who somehow submits
 * `is_internal: true` is told plainly that the field is not theirs, rather than
 * having their note quietly published to a thread they believed was private.
 * Quiet coercion is the more dangerous failure here.
 */
class StoreTicketCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $ticket = $this->route('ticket');

        return $ticket instanceof Ticket && (bool) $this->user()?->can('comment', $ticket);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'min:1', 'max:5000'],
            'is_internal' => [
                'sometimes',
                'boolean',
                Rule::prohibitedIf(fn (): bool => ! $this->canPostInternal()),
            ],
            'parent' => [
                'sometimes',
                'nullable',
                'string',
                'uuid',
                // A reply must belong to the same ticket — otherwise a crafted
                // uuid could graft a thread from a ticket the caller cannot see.
                Rule::exists('ticket_comments', 'uuid')
                    ->where('ticket_id', $this->ticketKey())
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.required' => 'Write something before posting.',
            'is_internal.prohibited' => 'Internal notes are only available to the IT team.',
            'parent.exists' => 'That comment is not part of this ticket.',
        ];
    }

    public function isInternal(): bool
    {
        return $this->canPostInternal() && (bool) $this->boolean('is_internal');
    }

    public function parentComment(): ?TicketComment
    {
        $uuid = $this->validated('parent');

        return is_string($uuid) && $uuid !== ''
            ? TicketComment::query()->where('uuid', $uuid)->first()
            : null;
    }

    private function canPostInternal(): bool
    {
        $ticket = $this->route('ticket');
        $user = $this->user();

        return $ticket instanceof Ticket
            && $user !== null
            && $user->can('commentInternal', $ticket);
    }

    private function ticketKey(): ?int
    {
        $ticket = $this->route('ticket');

        return $ticket instanceof Ticket ? $ticket->getKey() : null;
    }
}
