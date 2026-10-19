<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Http\Requests;

use App\Models\Ticket;
use Illuminate\Foundation\Http\FormRequest;

/**
 * WP-J NOT FIXED. Authorization is `TicketPolicy::reportNotFixed()` — the
 * reporter, on their own still-open ticket. Anyone else, or the same reporter
 * on another teacher's ticket reached through the community feed, is a 403.
 */
class ReportTicketNotFixedRequest extends FormRequest
{
    public function authorize(): bool
    {
        $ticket = $this->route('ticket');

        return $ticket instanceof Ticket && (bool) $this->user()?->can('reportNotFixed', $ticket);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // What the reporter tried, in their own words. Optional — "it still
            // doesn't work" is a complete report.
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
