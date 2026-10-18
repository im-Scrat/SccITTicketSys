<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Http\Requests;

use App\Enums\ProblemArea;
use App\Enums\RoomType;
use App\Models\Ticket;
use App\Support\Concerns\ValidatesLookupSlugs;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates ticket list parameters for the feed, the requester's own list, the
 * administrator directory and the technician queue (SRS FR-TKT-014).
 *
 * The **row scoping is not here** — it is applied unconditionally by
 * `TicketVisibility` inside every query, so no parameter this request accepts
 * can widen what the caller sees. What this does is keep the *shape* honest:
 * sort keys are allow-listed so a crafted value cannot reach raw SQL, and an
 * unrecognized status or priority slug is **rejected with 422 rather than
 * silently dropped**, so a filter chip never claims to be filtering when it is
 * not (the rule established in Phase 2.5).
 */
class IndexTicketsRequest extends FormRequest
{
    use ValidatesLookupSlugs;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('viewAny', Ticket::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:255'],
            'priority' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'room' => ['nullable', 'string', 'uuid'],
            'building' => ['nullable', 'string', 'uuid'],
            'pc_unit' => ['nullable', 'string', 'uuid'],
            'technician' => ['nullable', 'string', 'max:64'],
            'mine' => ['nullable', 'boolean'],
            'has_pc_unit' => ['nullable', 'boolean'],
            'breached' => ['nullable', 'boolean'],
            'awaiting_confirmation' => ['nullable', 'boolean'],
            // The technician queue only. `assigned` = every ticket owned by the
            // caller (what the dashboard counts); `active` (default) = tickets
            // with a live assignment row. Row scoping is still `TicketVisibility`.
            'scope' => ['nullable', 'string', Rule::in(['active', 'assigned'])],
            'due_soon' => ['nullable', 'boolean'],
            // Phase 2.9 — the requester's own list only (`/tickets/mine`): "resolved
            // in the last N days", the exact list behind the dashboard's
            // "Resolved (30 days)" figure. Bounded, so a crafted value cannot ask
            // for an absurd date range. The other surfaces validate it but ignore it.
            'resolved_within' => ['nullable', 'integer', 'min:1', 'max:365'],
            // Phase 2.9 — administrative directory only (NOT FIXED, awaiting an owner).
            'escalated' => ['nullable', 'boolean'],
            // Phase 2.10 — administrative directory only: the parameters a report
            // figure's drill-down link carries (SDD DD-88). Dates are school-local
            // and inclusive; lists are checked against their domains in after().
            'reported_from' => ['nullable', 'string', 'date_format:Y-m-d'],
            'reported_to' => ['nullable', 'string', 'date_format:Y-m-d'],
            'room_type' => ['nullable', 'string', 'max:255'],
            'without_room' => ['nullable', 'boolean'],
            'problem_area' => ['nullable', 'string', 'max:255'],
            'asset' => ['nullable', 'string', 'uuid'],
            'exclude_duplicates' => ['nullable', 'boolean'],
            'exclude_cancelled' => ['nullable', 'boolean'],
            'include_closed' => ['nullable', 'boolean'],
            'trashed' => ['nullable', 'string', Rule::in(['without', 'with', 'only'])],
            'sort' => ['nullable', 'string', Rule::in([
                // Table sorts.
                'created_at', 'updated_at', 'ticket_number', 'title', 'upvotes',
                'comments', 'status', 'priority', 'category', 'reporter',
                'technician', 'resolution_due_at',
                // Feed sorts.
                'recent',
            ])],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'cursor' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Reject an unknown slug in a comma-separated filter rather than ignoring
     * it — showing an unfiltered list under a filter chip is worse than an error.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            fn (Validator $v) => $this->validateLookupSlugs($v, 'status', 'ticket_statuses'),
            fn (Validator $v) => $this->validateLookupSlugs($v, 'priority', 'ticket_priorities'),
            fn (Validator $v) => $this->validateLookupSlugs($v, 'category', 'ticket_categories'),
            fn (Validator $v) => $this->validateList($v, 'room_type', RoomType::values()),
            fn (Validator $v) => $this->validateList($v, 'problem_area', ProblemArea::values()),
            function (Validator $v): void {
                $from = $this->input('reported_from');
                $to = $this->input('reported_to');

                // Both are date_format-checked above; compare only valid pairs.
                if (is_string($from) && is_string($to)
                    && ! $v->errors()->has('reported_from') && ! $v->errors()->has('reported_to')
                    && $to < $from) {
                    $v->errors()->add('reported_to', 'The end date must be on or after the start date.');
                }
            },
        ];
    }

    /**
     * A comma list whose every item must belong to a fixed domain.
     *
     * @param  list<string>  $allowed
     */
    private function validateList(Validator $validator, string $field, array $allowed): void
    {
        $raw = $this->input($field);

        if (! is_string($raw) || $raw === '' || $raw === 'all') {
            return;
        }

        foreach (array_filter(array_map('trim', explode(',', $raw))) as $value) {
            if (! in_array($value, $allowed, true)) {
                $validator->errors()->add($field, "\"{$value}\" is not a valid {$field}.");
            }
        }
    }
}
