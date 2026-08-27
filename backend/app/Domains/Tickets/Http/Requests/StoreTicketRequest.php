<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Http\Requests;

use App\Models\Ticket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a new ticket (SRS FR-TKT-001/003).
 *
 * Two rules carry real authorization weight rather than mere shape-checking:
 *
 *  - **`priority` is staff-only.** A requester describing a fault is not the
 *    right person to declare it Critical; the category's default carries it, and
 *    an administrator adjusts afterwards. Accepting it from anyone would make
 *    the SLA clock self-service.
 *  - **`reporter` is staff-only.** FR-TKT-003 permits filing *on behalf of*
 *    another person, but only for Technicians and Administrators — a teacher may
 *    only ever report as themselves.
 *
 * The PC unit and room are addressed by uuid and validated against the same
 * tables the narrow lookup serves, so a client cannot name equipment the lookup
 * would not have offered.
 */
class StoreTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', Ticket::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:5', 'max:255'],
            'description' => ['required', 'string', 'min:10', 'max:10000'],
            'category' => ['required', 'string', Rule::exists('ticket_categories', 'slug')->where('is_active', true)],

            'priority' => [
                'sometimes',
                'nullable',
                'string',
                Rule::exists('ticket_priorities', 'slug')->where('is_active', true),
                Rule::prohibitedIf(fn (): bool => ! $this->actorIsStaff()),
            ],
            'reporter' => [
                'sometimes',
                'nullable',
                'string',
                'uuid',
                Rule::exists('users', 'uuid')->whereNull('deleted_at'),
                Rule::prohibitedIf(fn (): bool => ! $this->actorIsStaff()),
            ],

            'pc_unit' => ['sometimes', 'nullable', 'string', 'uuid', Rule::exists('pc_units', 'uuid')->whereNull('deleted_at')],
            'room' => ['sometimes', 'nullable', 'string', 'uuid', Rule::exists('rooms', 'uuid')->whereNull('deleted_at')],

            'tags' => ['sometimes', 'array', 'max:10'],
            'tags.*' => ['string', Rule::exists('tags', 'slug')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.min' => 'Give the problem a title of at least 5 characters, so it can be recognised in the feed.',
            'description.min' => 'Describe what is wrong in at least 10 characters — what you were doing, and what happened.',
            'category.exists' => 'Choose one of the available categories.',
            'priority.prohibited' => 'Priority is set by the IT team after review.',
            'reporter.prohibited' => 'You can only report an issue as yourself.',
            'pc_unit.exists' => 'Choose an available PC unit.',
            'room.exists' => 'Choose an available room.',
        ];
    }

    /** Technicians and Administrators may set priority and file on behalf. */
    private function actorIsStaff(): bool
    {
        return in_array($this->user()?->role?->slug, ['administrator', 'technician'], true);
    }
}
