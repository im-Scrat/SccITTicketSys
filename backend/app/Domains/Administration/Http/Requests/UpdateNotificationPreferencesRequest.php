<?php

declare(strict_types=1);

namespace App\Domains\Administration\Http\Requests;

use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A batch of preference changes (SRS FR-NOT-002).
 *
 * `channel` and `notification_type` are validated against the enums, which are
 * the same values the table's two CHECK constraints enforce. Both layers matter:
 * the rule below gives the user a 422 that names the field, and the constraint
 * means a caller that is not this endpoint — a command, a seeder, a future
 * import — meets the same wall rather than writing a row the gate cannot read.
 *
 * There is no `user_id` field and there deliberately never will be. The subject
 * is the authenticated session; a request that could name a user would be a
 * request that could silence someone else's notifications.
 */
class UpdateNotificationPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'preferences' => ['required', 'array', 'min:1', 'max:64'],
            'preferences.*.channel' => ['required', Rule::in(NotificationChannel::values())],
            'preferences.*.notification_type' => ['required', Rule::in(NotificationType::values())],
            'preferences.*.is_enabled' => ['required', 'boolean'],
        ];
    }
}
