<?php

declare(strict_types=1);

namespace App\Domains\Administration\Http\Controllers;

use App\Domains\Administration\Http\Requests\IndexNotificationsRequest;
use App\Domains\Administration\Http\Resources\NotificationResource;
use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * **The notification centre's backend contract** (SRS FR-NOT-001/004/005).
 *
 * WP-2.7a builds no UI — that is WP-2.7b — but it owes WP-2.7b an API, and an
 * authorization boundary that is only asserted by tests against a service is a
 * boundary that has never met an HTTP request. These five endpoints are what
 * make "a user cannot reach another user's notifications" a testable property of
 * the running application rather than a property of a class.
 *
 * ── Every query is scoped by ownership, in one place ───────────────────────
 *
 * {@see own()} is the only way a row is fetched here. There is no branch for
 * administrators and no permission check, because a notification is addressed to
 * exactly one person — see `NotificationPolicy` for why the usual
 * administrator-sees-everything rule is deliberately absent.
 *
 * Route-model binding resolves `{notification:uuid}`, and the policy then
 * re-asserts ownership per record. Both halves are needed: binding alone would
 * happily hand over somebody else's row, and the scope alone would not protect
 * `show`.
 */
class NotificationController extends Controller
{
    /**
     * The caller's own notifications, newest first (FR-NOT-001).
     *
     * Filters are read from the request rather than composed by the client: an
     * unread-only view and a per-type view are what FR-NOT-005 names, and a
     * general-purpose filter language would be a query surface nobody asked for.
     */
    public function index(IndexNotificationsRequest $request): AnonymousResourceCollection
    {
        $query = $this->own($request);

        if ($request->boolean('unread')) {
            $query->whereNull('read_at');
        }

        if (($type = $request->validated('type')) !== null) {
            $query->where('type', $type);
        }

        return NotificationResource::collection(
            $query
                // `id` breaks the tie because `created_at` has no updated_at
                // sibling here and several notifications from one event share a
                // timestamp to the second.
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate(20)
                ->withQueryString(),
        );
    }

    /**
     * The unread badge count (FR-NOT-001).
     *
     * Its own endpoint rather than a field on the list, because the badge is
     * polled on a cadence the list is not, and it is served entirely from the
     * `notifications_unread` partial index — which exists in the baseline schema
     * for precisely this query.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'unread' => $this->own($request)->whereNull('read_at')->count(),
        ]);
    }

    /** One notification. The policy answers whose. */
    public function show(Request $request, Notification $notification): NotificationResource
    {
        $this->authorize('view', $notification);

        return new NotificationResource($notification);
    }

    /**
     * Mark one notification read (FR-NOT-004).
     *
     * Idempotent: `read_at` is stamped once and a second call leaves the
     * original timestamp alone, because when someone first read something is a
     * fact and re-reading it does not change that fact.
     */
    public function markRead(Request $request, Notification $notification): NotificationResource
    {
        $this->authorize('update', $notification);

        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return new NotificationResource($notification->refresh());
    }

    /** Restore a notification to unread (FR-NOT-004). */
    public function markUnread(Request $request, Notification $notification): NotificationResource
    {
        $this->authorize('update', $notification);

        $notification->forceFill(['read_at' => null])->save();

        return new NotificationResource($notification->refresh());
    }

    /**
     * Mark every unread notification read (FR-NOT-004, bulk).
     *
     * One `UPDATE ... WHERE read_at IS NULL` against the caller's own rows,
     * rather than a read-then-write loop: the set can be large, and a loop would
     * both cost a query per row and leave a half-cleared inbox if it failed
     * partway.
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $updated = $this->own($request)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['marked' => $updated]);
    }

    /**
     * The caller's own notifications — the single scoping rule for this
     * controller.
     *
     * @return Builder<Notification>
     */
    private function own(Request $request)
    {
        /** @var User $user */
        $user = $request->user();

        return Notification::query()->where('user_id', $user->getKey());
    }
}
