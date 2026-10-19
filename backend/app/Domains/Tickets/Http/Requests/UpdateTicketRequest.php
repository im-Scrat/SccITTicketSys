<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Http\Requests;

use App\Domains\Tickets\Actions\UpdateTicket;
use App\Models\Ticket;
use App\Models\TicketCategory;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Validator;

/**
 * Validates an edit to a ticket's descriptive fields (SRS FR-TKT-001).
 *
 * `status`, `priority`, `assigned_technician_id` and `duplicate_of_id` are
 * **prohibited** here rather than merely ignored. Each has its own audited
 * endpoint, and accepting-then-discarding them would let a caller believe a
 * status change had been applied when it had not. Refusing outright means the
 * API never quietly does less than it was asked.
 *
 * **The "Other" description follows the category it would be stored under**
 * (Phase 2.9, SDD DD-77) — the one sent, or else the ticket's current one:
 *  - moving a ticket **into** a category that asks for a description requires
 *    one;
 *  - a description, once given, **cannot be erased** while the category still
 *    asks for one — only changed — or the edit would leave behind exactly the
 *    ticket the create rule exists to prevent;
 *  - a legacy ticket filed under `other` before descriptions existed still
 *    saves without one, as long as its category is left alone;
 *  - a category that does not ask for one refuses a description
 *    ({@see UpdateTicket} clears the old one when the category moves away).
 *
 * **A retired category is kept, never chosen.** A ticket may only be moved
 * into an active category, but the category it is already in is accepted even
 * after it has been switched off (e.g. "Other Common Issues", retired on
 * 2026-10-16). The edit drawer always sends the category, so without this a
 * reporter correcting a typo in the title of such a ticket would be refused.
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
            'category' => ['sometimes', 'required', 'string', $this->availableCategory()],
            'category_detail' => ['sometimes', 'nullable', 'string', 'min:3', 'max:120'],
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
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['category', 'category_detail'])) {
                    return;
                }

                $this->checkCategoryDetail($validator);
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'category_detail.min' => 'Describe the kind of problem in at least 3 characters.',
            'category_detail.max' => 'Keep the kind of problem to 120 characters — the details go in the description.',
            'status.prohibited' => 'Change the status from the ticket’s status action, so the change is recorded.',
            'priority.prohibited' => 'Change the priority from the priority action, so the SLA is recalculated.',
            'technician.prohibited' => 'Assign a technician from the assignment action.',
            'duplicate_of.prohibited' => 'Mark a duplicate from the duplicate action.',
        ];
    }

    /**
     * An active category, or the one this ticket is already filed under —
     * see "A retired category is kept, never chosen" in the class docblock.
     */
    private function availableCategory(): Exists
    {
        $ticket = $this->route('ticket');
        $currentId = $ticket instanceof Ticket ? $ticket->category_id : null;

        return Rule::exists('ticket_categories', 'slug')->where(
            function (Builder $query) use ($currentId): void {
                $query->where('is_active', true);

                if ($currentId !== null) {
                    $query->orWhere('id', $currentId);
                }
            },
        );
    }

    private function checkCategoryDetail(Validator $validator): void
    {
        $ticket = $this->route('ticket');

        if (! $ticket instanceof Ticket) {
            return;
        }

        $ticket->loadMissing('category');
        $sent = $this->input('category');
        $changing = is_string($sent) && $sent !== $ticket->category?->slug;

        $target = $changing
            ? TicketCategory::query()->where('slug', $sent)->first()
            : $ticket->category;

        if (! $target instanceof TicketCategory) {
            return;
        }

        $raw = $this->input('category_detail');
        $detail = is_string($raw) && Str::trim($raw) !== '' ? $raw : null;

        if (! $target->requires_detail) {
            if ($detail !== null) {
                $validator->errors()->add(
                    'category_detail',
                    'Only an "Other" category takes a description of the kind of problem.',
                );
            }

            return;
        }

        if ($changing && ! $this->has('category_detail')) {
            // As on create: the current form always sends the field for a
            // category that asks for it, so its absence means a page loaded
            // before this rule — one with no field to show the error under.
            $validator->errors()->add(
                'category',
                'This category now asks for a short description — reload the page to add it.',
            );
        } elseif ($changing && $detail === null) {
            $validator->errors()->add('category_detail', 'Say in a few words what kind of problem it is.');
        } elseif (! $changing && $this->has('category_detail') && $detail === null && $ticket->category_detail !== null) {
            $validator->errors()->add(
                'category_detail',
                'The kind of problem can be changed, but not removed, while this category is chosen.',
            );
        }
    }
}
