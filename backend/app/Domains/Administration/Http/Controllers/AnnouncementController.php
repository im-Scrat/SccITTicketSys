<?php

declare(strict_types=1);

namespace App\Domains\Administration\Http\Controllers;

use App\Domains\Administration\Actions\ManageAnnouncement;
use App\Domains\Administration\Http\Requests\StoreAnnouncementRequest;
use App\Domains\Administration\Http\Requests\UpdateAnnouncementRequest;
use App\Domains\Administration\Http\Resources\AnnouncementResource;
use App\Domains\Administration\Services\AnnouncementVisibility;
use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * **Announcements** (SRS FR-NOT-010/011; WP-2.7c).
 *
 * Two surfaces on one entity, and — as with work support requests — the split
 * *is* the authorization boundary rather than a parameter on a shared endpoint:
 *
 *  - {@see index()} and {@see show()} are the **reader**. Audience-scoped for
 *    everyone, administrators included, through {@see AnnouncementVisibility}.
 *  - {@see manage()} and the writes below are the **management** surface,
 *    behind `system.announcements.manage`, where drafts and expired rows are
 *    visible because managing them requires it.
 *
 * A caller cannot cross between them by changing a query parameter, because
 * there is no parameter — they are different routes with different scopes.
 *
 * ── The list rule and the uuid rule are the same rule ─────────────────────
 *
 * {@see show()} authorizes through `AnnouncementPolicy::view()`, which asks
 * `AnnouncementVisibility::canRead()` — the same service {@see index()} scopes
 * with. That is what makes "an announcement absent from your list is
 * unreachable by its uuid" a property of the code rather than a claim in a
 * report (the DD-40 shape).
 *
 * ── Publication is never a request field ──────────────────────────────────
 *
 * There is no endpoint that accepts `is_active`. Publishing, unpublishing and
 * re-notifying are named operations, so a notification can never be a side
 * effect of saving an edit (decision D7).
 */
class AnnouncementController extends Controller
{
    public function __construct(
        private readonly ManageAnnouncement $announcements,
        private readonly AnnouncementVisibility $visibility,
    ) {}

    /* ------------------------------------------------------------ reader */

    /**
     * Announcements addressed to the caller, pinned first (FR-NOT-011).
     *
     * The dashboard widget shows the most recent three; this is the complete,
     * paginated list behind it (decision D2), so the fourth announcement
     * is readable rather than silently dropped.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return AnnouncementResource::collection(
            $this->visibility
                ->scopeReadable(Announcement::query(), $user)
                ->orderByDesc('is_pinned')
                ->orderByDesc('created_at')
                // `created_at` has no per-row uniqueness, and several
                // announcements published together share a timestamp.
                ->orderByDesc('id')
                ->paginate(20)
                ->withQueryString(),
        );
    }

    /** One announcement, if it is addressed to the caller. */
    public function show(Request $request, Announcement $announcement): AnnouncementResource
    {
        $this->authorize('view', $announcement);

        return new AnnouncementResource($announcement->loadMissing('createdBy'));
    }

    /* -------------------------------------------------------- management */

    /**
     * Every announcement, whatever its state or audience (FR-NOT-010).
     *
     * Deliberately unscoped: an administrator managing announcements must see
     * drafts, expired ones and announcements aimed at other audiences, because
     * those are exactly the ones needing attention. `withTrashed` is not
     * offered — a deleted announcement is gone from the surface and survives
     * only in the audit trail.
     */
    public function manage(Request $request): AnonymousResourceCollection
    {
        $this->authorize('manage', Announcement::class);

        $query = Announcement::query()->with('createdBy');

        if ($request->filled('audience')) {
            $query->where('audience', $request->string('audience')->toString());
        }

        if ($request->has('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        return AnnouncementResource::collection(
            $query
                ->orderByDesc('is_pinned')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate(20)
                ->withQueryString(),
        );
    }

    public function store(StoreAnnouncementRequest $request): JsonResponse
    {
        $this->authorize('create', Announcement::class);

        /** @var User $user */
        $user = $request->user();

        $announcement = $this->announcements->create($user, $request->validated());

        return (new AnnouncementResource($announcement))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateAnnouncementRequest $request, Announcement $announcement): AnnouncementResource
    {
        $this->authorize('update', $announcement);

        /** @var User $user */
        $user = $request->user();

        return new AnnouncementResource(
            $this->announcements->update($user, $announcement, $request->validated()),
        );
    }

    /** Make it live and tell its audience (FR-NOT-003 trigger 12). */
    public function publish(Request $request, Announcement $announcement): AnnouncementResource
    {
        $this->authorize('update', $announcement);

        /** @var User $user */
        $user = $request->user();

        return new AnnouncementResource($this->announcements->publish($user, $announcement));
    }

    /** Withdraw it. Notifies nobody. */
    public function unpublish(Request $request, Announcement $announcement): AnnouncementResource
    {
        $this->authorize('update', $announcement);

        /** @var User $user */
        $user = $request->user();

        return new AnnouncementResource($this->announcements->unpublish($user, $announcement));
    }

    /**
     * Tell the audience again, on purpose (decision D7).
     *
     * Its own endpoint precisely so that it cannot happen by accident: an
     * ordinary edit notifies nobody, and this is the only way to repeat.
     */
    public function notifyAgain(Request $request, Announcement $announcement): AnnouncementResource
    {
        $this->authorize('notify', $announcement);

        /** @var User $user */
        $user = $request->user();

        return new AnnouncementResource($this->announcements->notifyAgain($user, $announcement));
    }

    public function destroy(Request $request, Announcement $announcement): JsonResponse
    {
        $this->authorize('delete', $announcement);

        /** @var User $user */
        $user = $request->user();

        $this->announcements->delete($user, $announcement);

        return response()->json(status: 204);
    }
}
