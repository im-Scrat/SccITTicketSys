<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Http\Requests;

use App\Models\Role;
use App\Models\Ticket;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates assigning or reassigning a ticket (SRS FR-ASN-001).
 *
 * The candidate must hold a **technician or administrator** role, enforced here
 * rather than trusted from the client's dropdown — that is what stops a ticket
 * being booked out to a teacher by a stale UI or a hand-crafted request. It
 * mirrors the same restriction `AssignTechnicianRequest` applies to asset
 * custodianship.
 */
class AssignTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        $ticket = $this->route('ticket');

        return $ticket instanceof Ticket && (bool) $this->user()?->can('assign', $ticket);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'technician' => [
                'required',
                'string',
                'uuid',
                Rule::exists('users', 'uuid')
                    ->whereNull('deleted_at')
                    ->where('status', 'active')
                    ->where(fn (Builder $query): Builder => $query->whereIn(
                        'role_id',
                        Role::query()->select('id')->whereIn('slug', ['technician', 'administrator']),
                    )),
            ],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'technician.required' => 'Choose the technician to assign this ticket to.',
            'technician.exists' => 'Tickets can only be assigned to an active technician or administrator.',
        ];
    }
}
