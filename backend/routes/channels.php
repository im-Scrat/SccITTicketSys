<?php

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
|
| No channels are registered yet: WP-A ships the infrastructure only. The
| first are the floor-plan room channels (WP-E), e.g.
|
|     Broadcast::channel('floor-plan.room.{roomUuid}', fn (User $user) => …);
|
*/
