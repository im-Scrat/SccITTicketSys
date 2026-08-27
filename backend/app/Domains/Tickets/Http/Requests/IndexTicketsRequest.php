<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Http\Requests;

use App\Models\Ticket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
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
            fn (Validator $v) => $this->validateSlugs($v, 'status', 'ticket_statuses'),
            fn (Validator $v) => $this->validateSlugs($v, 'priority', 'ticket_priorities'),
            fn (Validator $v) => $this->validateSlugs($v, 'category', 'ticket_categories'),
        ];
    }

    private function validateSlugs(Validator $validator, string $field, string $table): void
    {
        $raw = $this->input($field);

        if (! is_string($raw) || $raw === '' || $raw === 'all') {
            return;
        }

        $submitted = array_values(array_filter(array_map('trim', explode(',', $raw))));

        if ($submitted === []) {
            return;
        }

        $known = DB::table($table)->whereIn('slug', $submitted)->pluck('slug')->all();

        foreach (array_diff($submitted, $known) as $unknown) {
            $validator->errors()->add($field, "\"{$unknown}\" is not a valid {$field}.");
        }
    }
}
