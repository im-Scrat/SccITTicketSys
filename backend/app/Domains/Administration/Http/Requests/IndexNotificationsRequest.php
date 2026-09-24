<?php

declare(strict_types=1);

namespace App\Domains\Administration\Http\Requests;

use App\Enums\NotificationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filters for the notification list (SRS FR-NOT-005).
 *
 * `authorize()` returns true because there is nothing to authorize at this
 * level: the endpoint lists the caller's own rows and the controller's scope is
 * the boundary. A permission check here would be either always true or a claim
 * about somebody else's notifications, which this endpoint never touches.
 *
 * `type` is validated against the enum rather than passed through, so an
 * unrecognised value is a 422 naming the problem instead of a query that
 * silently returns nothing and looks like an empty inbox.
 */
class IndexNotificationsRequest extends FormRequest
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
            'unread' => ['sometimes', 'boolean'],
            'type' => ['sometimes', 'nullable', Rule::in(NotificationType::values())],
        ];
    }
}
