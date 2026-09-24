<?php

declare(strict_types=1);

namespace App\Domains\WorkSupport\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Withdraw a request (SRS FR-WSR-014).
 *
 * The note is optional: a technician who no longer needs a part should not have
 * to justify saying so, and requiring a reason would leave stale requests in the
 * inbox rather than withdrawn. Who cancelled is recorded regardless.
 */
class CancelWorkSupportRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $request = $this->route('workSupportRequest');

        return $request !== null && (bool) $this->user()?->can('cancel', $request);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['cancellation_note' => ['nullable', 'string', 'max:500']];
    }
}
