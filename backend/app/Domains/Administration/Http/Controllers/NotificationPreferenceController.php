<?php

declare(strict_types=1);

namespace App\Domains\Administration\Http\Controllers;

use App\Domains\Administration\Http\Requests\UpdateNotificationPreferencesRequest;
use App\Domains\Administration\Services\NotificationPreferences;
use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * **The caller's own notification preferences** (SRS FR-NOT-002).
 *
 * The infrastructure half of preferences: the matrix a user can read and set.
 * The screen that renders it belongs to WP-2.7b; the gate that honours it is
 * `NotificationPreferences`, which every notification consults through
 * `ProjectNotification::via()`.
 *
 * ── Always your own, and there is no user parameter to change that ─────────
 *
 * Both endpoints resolve the subject from the session. There is no route
 * segment, query parameter or body field naming a user, so there is nothing for
 * a caller to tamper with — the same shape the profile endpoints already take,
 * and the reason no policy is needed here.
 *
 * ── The response is the whole matrix, not the stored rows ──────────────────
 *
 * `notification_preferences` is opt-out: an absent row means *enabled*. A client
 * handed only the stored rows would have to know that, and would render a
 * never-visited user's preferences as eighteen switches in an unknown state.
 * The service fills the gaps instead, so the API tells the truth in one shape.
 */
class NotificationPreferenceController extends Controller
{
    public function __construct(private readonly NotificationPreferences $preferences) {}

    /** The complete user × channel × type matrix, absent rows resolved. */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => $this->preferences->matrix($user),
            'meta' => [
                'channels' => NotificationChannel::options(),
                'types' => NotificationType::options(),
                // Stated explicitly so a client never has to infer it from an
                // empty list: silence means yes.
                'default_enabled' => true,
            ],
        ]);
    }

    /**
     * Set one or more cells of the matrix.
     *
     * Accepts a list rather than a single cell because the screen this serves
     * has eighteen switches and a "save" button; sending them one request at a
     * time would make a partial save the normal outcome of a flaky connection.
     */
    public function update(UpdateNotificationPreferencesRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        foreach ($request->validated('preferences') as $preference) {
            $this->preferences->set(
                $user,
                NotificationChannel::from($preference['channel']),
                NotificationType::from($preference['notification_type']),
                (bool) $preference['is_enabled'],
            );
        }

        return response()->json(['data' => $this->preferences->matrix($user)]);
    }
}
