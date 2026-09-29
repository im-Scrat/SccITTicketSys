<?php

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
Broadcast::channel(
    'floor-plan.room.{roomUuid}',
    fn (User $user, string $roomUuid): bool => app(FloorPlanAccess::class)->canView($user),
);
