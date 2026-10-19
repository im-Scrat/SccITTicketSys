<?php

use App\Domains\Analytics\Events\AdminDashboardChanged;
use App\Domains\Analytics\Services\AdminDashboardAccess;
use App\Domains\FloorPlan\Services\FloorPlanAccess;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Private broadcast channel authorization
|--------------------------------------------------------------------------
|
| Every channel this application broadcasts on is private, and each is
| authorized here by `GET|POST /broadcasting/auth` (registered in
| bootstrap/app.php, behind the same middleware as the API's feature routes).
|
| A channel callback is an authorization decision, not a convenience: it is
| the only thing standing between a socket and whatever that channel carries.
| Decide with the same policy or permission the equivalent HTTP read uses, so
| a subscription can never see more than the user could already open.
|
| Channel names are visible to the browser. Address records by UUID, never by
| auto-increment id (NFR-SEC-001) — which is why the installer's default
| `App.Models.User.{id}` channel is deliberately absent.
*/

/*
|--------------------------------------------------------------------------
| Interactive Floor Plan (WP-E) — FR-FP-007
|--------------------------------------------------------------------------
| Decided by `FloorPlanAccess::canView()` — the **same class** the read
| endpoint's `RoomLayoutService` and both floor-plan policies delegate to
| (see FloorPlanAccess's own docblock for why the Administrator role is
| checked alongside the permission). Never a bare `floorplan.view` string:
| `Gate::before` in `AppServiceProvider` would answer that string for a
| Technician or Teacher who holds a per-user `floorplan.view` grant, before
| this callback — the same per-user-grant trap WP-B found for the HTTP
| routes applies identically to a channel gate.
|
| The floor plan has no per-room scoping (every Administrator may open every
| room's plan — see RoomLayoutPolicy), so `$roomUuid` is not checked against
| an actual room here, matching the read endpoint's own refusal: a Teacher or
| Technician is refused identically whether the room is real or invented.
*/
/*
|--------------------------------------------------------------------------
| A user's own channel (Phase 2.9)
|--------------------------------------------------------------------------
| Every per-user realtime signal — each notification, including each
| recipient's own copy of an announcement, and "your AI analysis is ready" —
| goes out on its recipient's channel and nowhere else. There is deliberately
| no shared channel for anything user-specific: a broadcast to a shared
| channel is a broadcast to everyone subscribed to it.
|
| Exact string comparison against the authenticated user's own uuid. No role,
| no permission and no administrator exception: an administrator has no more
| business reading a teacher's private signals over a socket than anyone else.
*/
Broadcast::channel(
    'users.{uuid}',
    fn (User $user, string $uuid): bool => hash_equals((string) $user->uuid, $uuid),
);

/*
|--------------------------------------------------------------------------
| Live administrator dashboard (Phase 2.9)
|--------------------------------------------------------------------------
| Thin "something changed — refetch" signals (kind + uuid) from
| AdminDashboardChanged. Decided by AdminDashboardAccess (the Administrator
| role, never a grantable permission string — see that class).
*/
Broadcast::channel(
    AdminDashboardChanged::CHANNEL,
    fn (User $user): bool => app(AdminDashboardAccess::class)->canView($user),
);

Broadcast::channel(
    'floor-plan.room.{roomUuid}',
    fn (User $user, string $roomUuid): bool => app(FloorPlanAccess::class)->canView($user),
);

/*
|--------------------------------------------------------------------------
| Floorplan editors' presence (Phase 2.11) — SDD DD-103
|--------------------------------------------------------------------------
| Who else has this room's Floorplan open, and whether they are editing it
| (a client whisper, accepted only from members — `reverb.php`), so two
| administrators see each other *before* their saves collide.
|
| The same `FloorPlanAccess::canView()` gate as the room channel above, never a
| bare permission string. The member payload is exactly a uuid and a display
| name: `User::getAuthIdentifierForBroadcasting()` returns the uuid, so the
| broadcaster's own `user_id` field carries no internal integer id either
| (NFR-SEC-001).
*/
Broadcast::channel(
    'floor-plan.editors.{roomUuid}',
    fn (User $user, string $roomUuid): array|false => app(FloorPlanAccess::class)->canView($user)
        ? ['id' => $user->uuid, 'name' => $user->fullName()]
        : false,
);
