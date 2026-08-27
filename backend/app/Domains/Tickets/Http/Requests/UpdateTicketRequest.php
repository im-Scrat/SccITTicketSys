<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Http\Requests;

use App\Models\Ticket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates an edit to a ticket's descriptive fields (SRS FR-TKT-001).
 *
 * `status`, `priority`, `assigned_technician_id` and `duplicate_of_id` are
 * **prohibited** here rather than merely ignored. Each has its own audited
 * endpoint, and accepting-then-discarding them would let a caller believe a
 * status change had been applied when it had not. Refusing outright means the
 * API never quietly does less than it was asked.
 */
class UpdateTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        $ticket = $this->route('ticket');

        return $ticket instanceof Ticket && (bool) $this->user()?->can('update', $ticket);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'min:5', 'max:255'],
            'description' => ['sometimes', 'required', 'string', 'min:10', 'max:10000'],
            'category' => ['sometimes', 'required', 'string', Rule::exists('ticket_categories', 'slug')->where('is_active', true)],
            'tags' => ['sometimes', 'array', 'max:10'],
            'tags.*' => ['string', Rule::exists('tags', 'slug')],

            // Each of these has its own audited write path — see the docblock.
            'status' => ['prohibited'],
            'priority' => ['prohibited'],
            'technician' => ['prohibited'],
            'duplicate_of' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.prohibited' => 'Change the status from the ticket’s status action, so the change is recorded.',
            'priority.prohibited' => 'Change the priority from the priority action, so the SLA is recalculated.',
            'technician.prohibited' => 'Assign a technician from the assignment action.',
            'duplicate_of.prohibited' => 'Mark a duplicate from the duplicate action.',
        ];
    }
}
