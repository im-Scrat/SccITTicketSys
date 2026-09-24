<?php

declare(strict_types=1);

namespace App\Domains\WorkSupport\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The three administrator decisions, in one FormRequest (SRS FR-WSR-006/007/008).
 *
 * One class rather than three, because they share an authorization rule and
 * differ only in which fields they require — and three near-identical classes
 * is three places for that authorization to drift. The route decides which
 * decision is being made; {@see rules()} asks the route which one and validates
 * accordingly.
 *
 * `status` is **not** a field here, and cannot be. FR-WSR-004 requires
 * transitions to come from a map rather than from the client, so the decision
 * is the *endpoint*, and the payload only carries that decision's evidence.
 */
class DecideWorkSupportRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $request = $this->route('workSupportRequest');

        return $request !== null && (bool) $this->user()?->can('decide', $request);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return match ($this->decision()) {
            // FR-WSR-006. A date is required: "approve and reschedule" is one
            // decision, and an approval with no new date leaves the technician
            // exactly where they were.
            'approve' => [
                'rescheduled_to' => ['required', 'date'],
                'reschedule_reason' => ['nullable', 'string', 'max:1000'],
            ],

            // FR-WSR-007. The reason is what the technician will read before
            // walking to the office; OI-10 settled that the time is a note, not
            // a booking, so it is optional and unconstrained.
            'request-clarification' => [
                'clarification_reason' => ['required', 'string', 'min:5', 'max:1000'],
                'proposed_meeting_at' => ['nullable', 'date'],
            ],

            // FR-WSR-008. Mandatory, server-enforced, and enforced again by the
            // database's own CHECK (DR-020). `min:5` is the difference between
            // a reason and a keystroke.
            'decline' => [
                'decline_reason' => ['required', 'string', 'min:5', 'max:1000'],
            ],

            default => [],
        };
    }

    /** Which decision this request is, taken from the route rather than the body. */
    public function decision(): string
    {
        $action = $this->route()?->getActionMethod();

        return match ($action) {
            'approve' => 'approve',
            'requestClarification' => 'request-clarification',
            'decline' => 'decline',
            default => 'close',
        };
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rescheduled_to.required' => 'Set when the work can go ahead.',
            'clarification_reason.required' => 'Say what you want to discuss with the technician.',
            'clarification_reason.min' => 'Say what you want to discuss with the technician.',
            'decline_reason.required' => 'Explain why the request is declined. A decline without a reason is not recorded.',
            'decline_reason.min' => 'Explain why the request is declined. A decline without a reason is not recorded.',
        ];
    }
}
