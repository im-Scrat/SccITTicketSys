<?php

use App\Domains\Administration\Http\Controllers\AnnouncementController;
use App\Domains\Administration\Http\Controllers\NotificationController;
use App\Domains\Administration\Http\Controllers\NotificationPreferenceController;
use App\Domains\Analytics\Http\Controllers\DashboardController;
use App\Domains\Assets\Http\Controllers\Admin\AssetActionController;
use App\Domains\Assets\Http\Controllers\Admin\AssetAttachmentController;
use App\Domains\Assets\Http\Controllers\Admin\AssetController;
use App\Domains\Assets\Http\Controllers\Admin\AssetDashboardController;
use App\Domains\Assets\Http\Controllers\Admin\AssetHistoryController;
use App\Domains\Assets\Http\Controllers\Admin\CatalogController;
use App\Domains\Assets\Http\Controllers\Admin\PcUnitController;
use App\Domains\Assets\Http\Controllers\Admin\QrCodeController;
use App\Domains\Assets\Http\Controllers\AssetLookupController;
use App\Domains\Assets\Http\Controllers\QrScanController;
use App\Domains\FloorPlan\Http\Controllers\Admin\FloorPlanController;
use App\Domains\FloorPlan\Http\Controllers\Admin\FloorPlanPositionController;
use App\Domains\FloorPlan\Http\Controllers\Admin\RoomLayoutController;
use App\Domains\Identity\Http\Controllers\Admin\RegistrationReviewController;
use App\Domains\Identity\Http\Controllers\Admin\RoleController;
use App\Domains\Identity\Http\Controllers\Admin\UserActionController;
use App\Domains\Identity\Http\Controllers\Admin\UserAuditController;
use App\Domains\Identity\Http\Controllers\Admin\UserBulkController;
use App\Domains\Identity\Http\Controllers\Admin\UserController;
use App\Domains\Identity\Http\Controllers\Admin\UserDashboardController;
use App\Domains\Identity\Http\Controllers\Admin\UserExportController;
use App\Domains\Identity\Http\Controllers\Admin\UserLifecycleController;
use App\Domains\Identity\Http\Controllers\Admin\UserPermissionController;
use App\Domains\Identity\Http\Controllers\Admin\UserRoleController;
use App\Domains\Identity\Http\Controllers\AuthController;
use App\Domains\Identity\Http\Controllers\PasswordController;
use App\Domains\Identity\Http\Controllers\PasswordResetController;
use App\Domains\Identity\Http\Controllers\ProfileController;
use App\Domains\Identity\Http\Controllers\RegistrationController;
use App\Domains\KnowledgeBase\Http\Controllers\Admin\AiPredictionController;
use App\Domains\Locations\Http\Controllers\Admin\BuildingController;
use App\Domains\Locations\Http\Controllers\Admin\FloorController;
use App\Domains\Locations\Http\Controllers\Admin\LocationAuditController;
use App\Domains\Locations\Http\Controllers\Admin\LocationDashboardController;
use App\Domains\Locations\Http\Controllers\Admin\LocationTreeController;
use App\Domains\Locations\Http\Controllers\Admin\RoomController;
use App\Domains\Locations\Http\Controllers\LocationLookupController;
use App\Domains\Maintenance\Http\Controllers\Admin\MaintenanceDirectoryController;
use App\Domains\Maintenance\Http\Controllers\HardwareReplacementController;
use App\Domains\Maintenance\Http\Controllers\MaintenanceChecklistController;
use App\Domains\Maintenance\Http\Controllers\MaintenanceEvidenceController;
use App\Domains\Maintenance\Http\Controllers\MaintenanceNoteController;
use App\Domains\Maintenance\Http\Controllers\MaintenanceOptionsController;
use App\Domains\Maintenance\Http\Controllers\MaintenanceQueueController;
use App\Domains\Maintenance\Http\Controllers\MaintenanceRecordController;
use App\Domains\Maintenance\Http\Controllers\QrProofOfWorkController;
use App\Domains\Tickets\Http\Controllers\Admin\TicketAssignmentController;
use App\Domains\Tickets\Http\Controllers\Admin\TicketDirectoryController;
use App\Domains\Tickets\Http\Controllers\TechnicianQueueController;
use App\Domains\Tickets\Http\Controllers\TicketAiAnalysisController;
use App\Domains\Tickets\Http\Controllers\TicketController;
use App\Domains\Tickets\Http\Controllers\TicketFeedController;
use App\Domains\Tickets\Http\Controllers\TicketOptionsController;
use App\Domains\Tickets\Http\Controllers\TicketParticipationController;
use App\Domains\WorkSupport\Http\Controllers\Admin\WorkSupportRequestDecisionController;
use App\Domains\WorkSupport\Http\Controllers\TechnicianSubmissionController;
use App\Domains\WorkSupport\Http\Controllers\WorkSupportAttachmentController;
use App\Domains\WorkSupport\Http\Controllers\WorkSupportRequestController;
use App\Http\Controllers\BroadcastingConfigController;
use App\Http\Controllers\HealthController;
use App\Http\Middleware\AuthenticateSession;
use App\Models\AiPrediction;
use App\Models\FloorPlanPosition;
use App\Models\RoomLayout;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Identity & Access (Phase 2.2) + User Management (Phase 2.3)
| + Location Management & role dashboards (Phase 2.4)
|--------------------------------------------------------------------------
| Sanctum SPA cookie auth (single-origin). `/sanctum/csrf-cookie` is provided
| by Sanctum. Login lockout/throttling is handled in AuthService (FR-AUTH-006);
| register/password endpoints carry per-IP throttles (NFR-SEC-009). The admin
| User Management surface is gated per-ability (users.view/create/update/delete)
| plus per-record policies, and sits behind the force-password-reset gate.
*/

// Public (guest) endpoints.
Route::post('/register', [RegistrationController::class, 'store'])
    ->middleware('throttle:registrations');
Route::post('/login', [AuthController::class, 'login']);
Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink'])
    ->middleware('throttle:password');
Route::post('/reset-password', [PasswordResetController::class, 'reset'])
    ->middleware('throttle:password');

/*
|--------------------------------------------------------------------------
| QR scan entry (WP-2.6b) — PUBLIC BY DESIGN
|--------------------------------------------------------------------------
| The one feature route outside `auth:sanctum`, and it is deliberate. A printed
| label is a public artifact: anyone can photograph it, so the scan must be
| *resolvable and loggable* without a session, and must grant nothing
| (FR-QR-010, SDD DD-47). An unauthenticated scan discloses no target
| information at all and is answered with "go and sign in".
|
| `statefulApi()` runs on the whole API group, so when a session cookie *is*
| present `$request->user()` resolves here without any extra wiring — one
| endpoint serves both states rather than two endpoints that could drift.
|
| The `{code}` pattern is bounded to the issued alphabet so a tampered value
| cannot reach the controller as a path or a URL; the resolver bounds the length
| again for callers that are not HTTP. `throttle:qr-scan` carries two ceilings,
| per client and per account (FR-QR-013).
*/
Route::post('/qr/{code}/scan', [QrScanController::class, 'scan'])
    ->middleware('throttle:qr-scan')
    ->where('code', '[A-Za-z0-9-]{1,64}');

// Authenticated endpoints.
Route::middleware('auth:sanctum')->group(function () {
    // Logout is always available to an authenticated session (even if the
    // account was just suspended) so the session can be cleared.
    Route::post('/logout', [AuthController::class, 'logout']);

    // Everything else requires an active account (SDD DD-19). AuthenticateSession
    // enables "invalidate other sessions" on password change (FR-AUTH-011).
    Route::middleware(['active', AuthenticateSession::class])->group(function () {
        // Reachable even under a forced password reset so the user can comply.
        Route::get('/user', [AuthController::class, 'me']);
        Route::put('/password', [PasswordController::class, 'update']);

        // Self-service profile (name) edit (FR-USER-008).
        Route::put('/profile', [ProfileController::class, 'update']);

        // Reverb app KEY for the SPA's socket (WP-A). Same floor as
        // /broadcasting/auth: without it a key opens nothing worth having.
        Route::middleware('password.current')
            ->get('/broadcasting/config', BroadcastingConfigController::class);

        /*
        |------------------------------------------------------------------
        | Scan-scoped PC panel (WP-2.6b) — FR-QR-012, SDD DD-49
        |------------------------------------------------------------------
        | The authenticated half of the scan flow. Deliberately **not** under
        | the `/admin` prefix and **not** gated by any `assets.*` permission: a
        | Technician holds none (DD-38), and this surface exists so they can
        | reach the machine they are working on without one (FR-AST-013).
        |
        | There is no route-level `can:` gate at all, because no permission can
        | express the rule. `maintenance.view` is held by administrators and
        | technicians alike and says nothing about *which* machine; the control
        | is `PcUnitPolicy::viewScanned`, which asks `ScannedPcAccess` whether
        | this person has work on this unit. The permission floor is checked
        | inside the controller so a caller without it receives the same
        | non-disclosing refusal the public scan endpoint gives.
        |
        | `password.current` matches every other feature route: a user under a
        | forced password reset does not get a working panel.
        */
        Route::middleware('password.current')
            ->get('/qr/{code}/panel', [QrScanController::class, 'panel'])
            ->where('code', '[A-Za-z0-9-]{1,64}');

        /*
        |------------------------------------------------------------------
        | Proof of work from the scanned workflow (WP-2.6b Stage D)
        |------------------------------------------------------------------
        | FR-MNT-009/010/012, FR-QR-008; SDD DD-50.
        |
        | `can:maintenance.update` **is** meaningful here, unlike on the panel
        | above: attaching evidence and moving a record is a maintenance write,
        | and it is the identical floor the module's own evidence route carries.
        | It stays a floor — *whose* record may be written is decided per record
        | by `MaintenanceVisibility::canWork()`, and *which machine* by
        | `PcUnitPolicy::viewScanned`, exactly as on the panel.
        |
        | Throttled per account. The panel is a read and the scan endpoint has
        | its own public ceilings; this one accepts file uploads, so a runaway
        | client retrying a submission must cost the server a bounded amount.
        */
        Route::middleware(['password.current', 'can:maintenance.update'])
            ->group(function () {
                Route::get('/qr/{code}/work', [QrProofOfWorkController::class, 'index'])
                    ->where('code', '[A-Za-z0-9-]{1,64}');

                Route::post('/qr/{code}/proof', [QrProofOfWorkController::class, 'store'])
                    ->middleware('throttle:qr-proof')
                    ->where('code', '[A-Za-z0-9-]{1,64}');

                /*
                | Raising a work support request (WP-2.6b Stage E) —
                | FR-WSR-001/002/003.
                |
                | Addressed by the printed code, exactly like proof of work, so
                | the machine is resolved server-side and there is no PC
                | identifier for a caller to change. `ScannedPcAccess` decides
                | whether this technician may raise anything against this
                | machine at all.
                */
                Route::post('/qr/{code}/support-requests', [WorkSupportRequestController::class, 'store'])
                    ->middleware('throttle:qr-proof')
                    ->where('code', '[A-Za-z0-9-]{1,64}');
            });

        /*
        |------------------------------------------------------------------
        | Work support requests (WP-2.6b Stage E) — FR-WSR-009/014
        |------------------------------------------------------------------
        | The technician's own tracking surface. Gated on `maintenance.view`
        | as a floor only (Client decision OD-4 — no `wsr.*` permission was
        | invented); `WorkSupportVisibility` decides whose rows, on the list
        | and on the single record alike, so a request absent from the list is
        | equally unreachable by uuid (FR-WSR-009).
        |
        | Cancel and acknowledge are **named POST operations**, never a PATCH
        | of a status field — FR-WSR-004 requires the transition map to be the
        | only thing that writes `status`, and that applies to the technician's
        | own moves as strictly as to the administrator's.
        */
        Route::middleware(['password.current', 'can:maintenance.view'])->group(function () {
            Route::get('/work-support-requests', [WorkSupportRequestController::class, 'index']);
            Route::get('/work-support-requests/{workSupportRequest:uuid}', [WorkSupportRequestController::class, 'show']);
            Route::post('/work-support-requests/{workSupportRequest:uuid}/cancel', [WorkSupportRequestController::class, 'cancel']);
            Route::post('/work-support-requests/{workSupportRequest:uuid}/acknowledge', [WorkSupportRequestController::class, 'acknowledge']);

            /*
            | Evidence on a support request (WP-2.6b Stage F) — FR-WSR-003/005.
            |
            | **Nested on purpose, and there is deliberately no
            | `GET /attachments/{uuid}`.** The parent is authorized first and
            | the child must belong to it, so an attachment's own identifier is
            | never sufficient for access — an identifier is not an entitlement
            | (DD-47). A harvested or guessed uuid is refused identically
            | whether it belongs to another request, another technician, or to
            | nothing at all.
            |
            | No new ability: reading the evidence is part of reading the
            | request, so this authorizes `view` on the parent and adds no
            | permission of any kind.
            */
            Route::get(
                '/work-support-requests/{workSupportRequest:uuid}/attachments/{attachment:uuid}',
                [WorkSupportAttachmentController::class, 'download'],
            )->scopeBindings();

            /*
            | The technician's combined submission history — FR-WSR-009.
            |
            | Named for the person rather than the entity: this is *what I
            | submitted*, of which support requests are one kind and proof of
            | work the other. It takes no identifier at all — the subject is the
            | session — so "someone else's submissions" is not a request this
            | route can express.
            */
            Route::get('/technician/submissions', [TechnicianSubmissionController::class, 'index']);
        });

        /*
        |------------------------------------------------------------------
        | Notification centre (Phase 2.7 / WP-2.7a, WP-2.7b) — FR-NOT-001..008
        |------------------------------------------------------------------
        | Every route here is scoped to the caller. There is no `can:` gate,
        | because no permission can express "your own notifications": the
        | control is `NotificationPolicy`, which compares the notifiable to the
        | authenticated user and refuses another person's row by uuid rather
        | than merely omitting it from the list.
        |
        | `password.current` matches every other feature route: a user under a
        | forced password reset does not get a working notification centre.
        |
        | Literal paths precede {uuid} wildcards, and `whereUuid` keeps a
        | non-uuid path segment a clean 404 instead of a Postgres cast error.
        */
        Route::middleware('password.current')->group(function () {
            Route::get('/notifications', [NotificationController::class, 'index']);
            Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
            Route::patch('/notifications/read-all', [NotificationController::class, 'markAllRead']);

            Route::get('/notifications/{notification}', [NotificationController::class, 'show'])
                ->whereUuid('notification');
            Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markRead'])
                ->whereUuid('notification');
            Route::patch('/notifications/{notification}/unread', [NotificationController::class, 'markUnread'])
                ->whereUuid('notification');

            Route::get('/notification-preferences', [NotificationPreferenceController::class, 'index']);
            Route::put('/notification-preferences', [NotificationPreferenceController::class, 'update']);

            /*
             * Announcements — the READER (FR-NOT-011, WP-2.7c).
             *
             * Open to every authenticated user and scoped by audience, not by
             * permission: an announcement is addressed to people because of the
             * role they hold, and `AnnouncementVisibility` answers both the list
             * question and the single-record question with the same rule. A
             * reader outside an announcement's audience is refused by uuid, not
             * merely shown a shorter list.
             *
             * The literal segment is declared before the wildcard so `/mine`
             * style additions later cannot be swallowed as a uuid.
             */
            Route::get('/announcements', [AnnouncementController::class, 'index']);
            Route::get('/announcements/{announcement}', [AnnouncementController::class, 'show'])
                ->whereUuid('announcement');
        });

        /*
        |------------------------------------------------------------------
        | Announcement management (Phase 2.7 / WP-2.7c) — ADMINISTRATOR ONLY
        |------------------------------------------------------------------
        | Every route below requires `system.announcements.manage`, which the
        | Client's SS8.4 matrix seeds to Administrators alone. WP-2.7c
        | deliberately did NOT split it into create/update/delete abilities
        | (decision D5): one management ability, and the policy re-asserts it
        | per record.
        |
        | Publication is a named operation rather than a field, so no request
        | body can make an announcement live -- and therefore no request body
        | can notify an audience as a side effect (decision D7). Re-notifying
        | is its own endpoint for exactly the same reason.
        */
        Route::middleware(['password.current', 'can:system.announcements.manage'])
            ->prefix('admin')
            ->whereUuid('announcement')
            ->group(function () {
                Route::get('/announcements', [AnnouncementController::class, 'manage']);
                Route::post('/announcements', [AnnouncementController::class, 'store']);
                Route::put('/announcements/{announcement}', [AnnouncementController::class, 'update']);
                Route::post('/announcements/{announcement}/publish', [AnnouncementController::class, 'publish']);
                Route::post('/announcements/{announcement}/unpublish', [AnnouncementController::class, 'unpublish']);
                Route::post('/announcements/{announcement}/notify', [AnnouncementController::class, 'notifyAgain']);
                Route::delete('/announcements/{announcement}', [AnnouncementController::class, 'destroy']);
            });

        // Feature routes additionally require a current password (FR-USER force reset).
        Route::middleware('password.current')->prefix('admin')->group(function () {
            // Registration review queue (Phase 2.2, extended in 2.3).
            Route::middleware('can:users.update')->group(function () {
                Route::get('/registrations', [RegistrationReviewController::class, 'index']);
                Route::get('/registrations/{user:uuid}', [RegistrationReviewController::class, 'show']);
                Route::put('/registrations/{user:uuid}', [RegistrationReviewController::class, 'update']);
                Route::post('/registrations/{user:uuid}/approve', [RegistrationReviewController::class, 'approve']);
                Route::post('/registrations/{user:uuid}/reject', [RegistrationReviewController::class, 'reject']);
            });

            // Role catalog + dashboard + export (literal paths before the {user} wildcard).
            Route::middleware('can:users.view')->group(function () {
                Route::get('/roles', [RoleController::class, 'index']);
                Route::get('/users/dashboard', [UserDashboardController::class, 'index']);
                Route::get('/users/export', [UserExportController::class, 'index']);
            });

            // User directory reads.
            Route::middleware('can:users.view')->group(function () {
                Route::get('/users', [UserController::class, 'index']);
                Route::get('/users/{user:uuid}', [UserController::class, 'show'])->withTrashed();
                Route::get('/users/{user:uuid}/audit', [UserAuditController::class, 'index'])->withTrashed();
                Route::get('/users/{user:uuid}/permissions', [UserPermissionController::class, 'index']);
            });

            // Create.
            Route::post('/users', [UserController::class, 'store'])->middleware('can:users.create');

            // Mutations (update / role / permissions / lifecycle / actions / bulk).
            Route::middleware('can:users.update')->group(function () {
                Route::put('/users/{user:uuid}', [UserController::class, 'update']);
                Route::put('/users/{user:uuid}/role', [UserRoleController::class, 'update']);
                Route::put('/users/{user:uuid}/permissions', [UserPermissionController::class, 'update']);
                Route::post('/users/bulk', [UserBulkController::class, 'store']);

                Route::post('/users/{user:uuid}/activate', [UserLifecycleController::class, 'activate']);
                Route::post('/users/{user:uuid}/suspend', [UserLifecycleController::class, 'suspend']);
                Route::post('/users/{user:uuid}/reactivate', [UserLifecycleController::class, 'reactivate']);
                Route::post('/users/{user:uuid}/deactivate', [UserLifecycleController::class, 'deactivate']);

                Route::post('/users/{user:uuid}/force-password-reset', [UserActionController::class, 'forcePasswordReset']);
                Route::post('/users/{user:uuid}/unlock', [UserActionController::class, 'unlock']);
                Route::post('/users/{user:uuid}/send-password-reset', [UserActionController::class, 'sendPasswordReset']);
                Route::post('/users/{user:uuid}/resend-approval', [UserActionController::class, 'resendApproval']);
                Route::post('/users/{user:uuid}/resend-rejection', [UserActionController::class, 'resendRejection']);
            });

            // Archive (soft delete) + restore.
            Route::middleware('can:users.delete')->group(function () {
                Route::delete('/users/{user:uuid}', [UserController::class, 'destroy']);
                Route::post('/users/{user:uuid}/restore', [UserController::class, 'restore'])->withTrashed();
            });
        });

        /*
        |------------------------------------------------------------------
        | Narrow location lookup (Phase 2.4) — FR-LOC-011
        |------------------------------------------------------------------
        | The only location data a non-administrator can reach: the room field a
        | form needs, nothing else. Authorized by `selectLocation` — the ability
        | the *consuming workflow's* permission grants (tickets.create,
        | maintenance.view, assets.transfer, …), never by a `locations.*`
        | permission — and answered with labels of selectable locations only.
        |
        | Under `/lookups`, not `/locations`, so the route list itself shows this
        | is a form helper and not the Locations module.
        */
        Route::middleware('password.current')
            ->prefix('lookups')
            ->group(function () {
                Route::get('/rooms', [LocationLookupController::class, 'rooms']);
                Route::get('/buildings', [LocationLookupController::class, 'buildings']);
                Route::get('/floors', [LocationLookupController::class, 'floors']);

                /*
                 * Narrow equipment lookup (Phase 2.5) — the asset counterpart of
                 * the room lookup above, and the only asset data a
                 * non-administrator can reach. Authorized by `selectAsset` /
                 * `selectPcUnit`, which the *consuming workflow's* permission
                 * grants (tickets.create, maintenance.view, …) and never an
                 * `assets.*` permission. Labels only (SDD DD-38).
                 */
                Route::get('/assets', [AssetLookupController::class, 'assets']);
                Route::get('/pc-units', [AssetLookupController::class, 'pcUnits']);
            });

        /*
        |------------------------------------------------------------------
        | Location Management (Phase 2.4) — FR-LOC-001..010 — ADMIN ONLY
        |------------------------------------------------------------------
        | The whole module — directory, tree, dashboard, detail pages and every
        | write — is gated per-ability on `locations.view/create/update/delete`,
        | which only Administrators hold, plus per-record policies that
        | additionally refuse an archive that would strand live occupants
        | (FR-LOC-004). Literal paths precede {uuid} wildcards.
        */

        // `whereUuid` keeps a non-uuid path segment a clean 404 instead of a
        // Postgres cast error on the `uuid` columns — numeric ids are never
        // addressable (NFR-SEC-001, AC-G2).
        Route::middleware('password.current')
            ->prefix('admin')
            ->whereUuid(['building', 'floor', 'room'])
            ->group(function () {
                // Reads.
                Route::middleware('can:locations.view')->group(function () {
                    Route::get('/locations/dashboard', LocationDashboardController::class);
                    Route::get('/locations/tree', LocationTreeController::class);

                    Route::get('/buildings', [BuildingController::class, 'index']);
                    Route::get('/buildings/{building:uuid}', [BuildingController::class, 'show'])->withTrashed();
                    Route::get('/buildings/{building:uuid}/audit', [LocationAuditController::class, 'building'])->withTrashed();
                    Route::get('/buildings/{building:uuid}/floors', [FloorController::class, 'index'])->withTrashed();

                    Route::get('/floors/{floor:uuid}', [FloorController::class, 'show'])->withTrashed();
                    Route::get('/floors/{floor:uuid}/audit', [LocationAuditController::class, 'floor'])->withTrashed();

                    Route::get('/rooms', [RoomController::class, 'index']);
                    Route::get('/rooms/{room:uuid}', [RoomController::class, 'show'])->withTrashed();
                    Route::get('/rooms/{room:uuid}/audit', [LocationAuditController::class, 'room'])->withTrashed();
                });

                // Create.
                Route::middleware('can:locations.create')->group(function () {
                    Route::post('/buildings', [BuildingController::class, 'store']);
                    Route::post('/buildings/{building:uuid}/floors', [FloorController::class, 'store']);
                    Route::post('/rooms', [RoomController::class, 'store']);
                });

                // Update / availability / occupant reassignment.
                Route::middleware('can:locations.update')->group(function () {
                    Route::put('/buildings/{building:uuid}', [BuildingController::class, 'update']);
                    Route::post('/buildings/{building:uuid}/activate', [BuildingController::class, 'activate']);
                    Route::post('/buildings/{building:uuid}/deactivate', [BuildingController::class, 'deactivate']);

                    Route::put('/floors/{floor:uuid}', [FloorController::class, 'update']);

                    Route::put('/rooms/{room:uuid}', [RoomController::class, 'update']);
                    Route::post('/rooms/{room:uuid}/activate', [RoomController::class, 'activate']);
                    Route::post('/rooms/{room:uuid}/deactivate', [RoomController::class, 'deactivate']);
                    Route::post('/rooms/{room:uuid}/reassign', [RoomController::class, 'reassign']);
                });

                // Archive (soft delete) + restore. Cascades down the hierarchy.
                Route::middleware('can:locations.delete')->group(function () {
                    Route::delete('/buildings/{building:uuid}', [BuildingController::class, 'destroy']);
                    Route::post('/buildings/{building:uuid}/restore', [BuildingController::class, 'restore'])->withTrashed();

                    Route::delete('/floors/{floor:uuid}', [FloorController::class, 'destroy']);
                    Route::post('/floors/{floor:uuid}/restore', [FloorController::class, 'restore'])->withTrashed();

                    Route::delete('/rooms/{room:uuid}', [RoomController::class, 'destroy']);
                    Route::post('/rooms/{room:uuid}/restore', [RoomController::class, 'restore'])->withTrashed();
                });
            });

        /*
        |------------------------------------------------------------------
        | Asset Management (Phase 2.5) — FR-AST-*, FR-PC-*, FR-QR-* — ADMIN ONLY
        |------------------------------------------------------------------
        | The whole module — dashboard, catalog, directories, detail pages,
        | history, QR and every write — is gated per-ability on
        | `assets.view/create/update/delete/transfer/dispose`, which only
        | Administrators hold (SDD DD-38), plus per-record policies.
        |
        | Two gates cannot be expressed as route middleware and live in the
        | FormRequest/Policy instead:
        |   - status changes into `retired`/`disposed` require `assets.dispose`
        |     rather than `assets.update` (DD-33), which depends on the payload;
        |   - archiving is refused with a 422 blocker report while the record
        |     still has installed components or open tickets (DD-29).
        |
        | Literal paths precede {uuid} wildcards. `whereUuid` keeps a non-uuid
        | segment a clean 404 instead of a Postgres cast error on the `uuid`
        | columns — numeric ids are never addressable (NFR-SEC-001, AC-G2).
        */
        Route::middleware('password.current')
            ->prefix('admin')
            ->whereUuid(['asset', 'pc_unit', 'attachment'])
            ->group(function () {
                // Reads.
                Route::middleware('can:assets.view')->group(function () {
                    Route::get('/assets/dashboard', AssetDashboardController::class);
                    Route::get('/assets/catalog', CatalogController::class);

                    Route::get('/assets', [AssetController::class, 'index']);
                    Route::get('/assets/{asset:uuid}', [AssetController::class, 'show'])->withTrashed();
                    Route::get('/assets/{asset:uuid}/history', [AssetHistoryController::class, 'assetTimeline'])->withTrashed();
                    Route::get('/assets/{asset:uuid}/audit', [AssetHistoryController::class, 'assetAudit'])->withTrashed();
                    Route::get('/assets/{asset:uuid}/attachments', [AssetAttachmentController::class, 'index'])->withTrashed();
                    Route::get('/assets/{asset:uuid}/qr', [QrCodeController::class, 'index'])->withTrashed();

                    Route::get('/pc-units', [PcUnitController::class, 'index']);
                    Route::get('/pc-units/{pc_unit:uuid}', [PcUnitController::class, 'show'])->withTrashed();
                    Route::get('/pc-units/{pc_unit:uuid}/history', [AssetHistoryController::class, 'pcUnitTimeline'])->withTrashed();
                    Route::get('/pc-units/{pc_unit:uuid}/audit', [AssetHistoryController::class, 'pcUnitAudit'])->withTrashed();
                    // WP-G — the real maintenance history (full records, not
                    // the timeline's lossy projection). Same can:assets.view
                    // floor as every other pc-units read on this line.
                    Route::get('/pc-units/{pc_unit:uuid}/maintenance', [AssetHistoryController::class, 'pcUnitMaintenance'])->withTrashed();
                    Route::get('/pc-units/{pc_unit:uuid}/attachments', [AssetAttachmentController::class, 'index'])->withTrashed();
                    Route::get('/pc-units/{pc_unit:uuid}/qr', [QrCodeController::class, 'index'])->withTrashed();

                    // Streamed from a private disk; the policy is re-checked
                    // against the owning record on every download.
                    Route::get('/asset-attachments/{attachment:uuid}', [AssetAttachmentController::class, 'download']);
                });

                // Create.
                Route::middleware('can:assets.create')->group(function () {
                    Route::post('/assets', [AssetController::class, 'store']);
                    Route::post('/pc-units', [PcUnitController::class, 'store']);
                });

                // Update — attributes, specification, custodianship, QR, files.
                Route::middleware('can:assets.update')->group(function () {
                    Route::put('/assets/{asset:uuid}', [AssetController::class, 'update']);
                    Route::post('/assets/{asset:uuid}/assign', [AssetActionController::class, 'assign']);

                    Route::put('/pc-units/{pc_unit:uuid}', [PcUnitController::class, 'update']);
                    Route::put('/pc-units/{pc_unit:uuid}/specification', [PcUnitController::class, 'updateSpecification']);

                    Route::post('/assets/{asset:uuid}/attachments', [AssetAttachmentController::class, 'store']);
                    Route::post('/pc-units/{pc_unit:uuid}/attachments', [AssetAttachmentController::class, 'store']);
                    Route::delete('/asset-attachments/{attachment:uuid}', [AssetAttachmentController::class, 'destroy']);

                    Route::post('/assets/{asset:uuid}/qr', [QrCodeController::class, 'store']);
                    Route::post('/assets/{asset:uuid}/qr/regenerate', [QrCodeController::class, 'regenerate']);
                    Route::post('/assets/{asset:uuid}/qr/revoke', [QrCodeController::class, 'revoke']);
                    Route::get('/assets/{asset:uuid}/qr/print', [QrCodeController::class, 'print']);

                    Route::post('/pc-units/{pc_unit:uuid}/qr', [QrCodeController::class, 'store']);
                    Route::post('/pc-units/{pc_unit:uuid}/qr/regenerate', [QrCodeController::class, 'regenerate']);
                    Route::post('/pc-units/{pc_unit:uuid}/qr/revoke', [QrCodeController::class, 'revoke']);
                    Route::get('/pc-units/{pc_unit:uuid}/qr/print', [QrCodeController::class, 'print']);
                });

                // Lifecycle status. The route gate is the *floor*; the request
                // raises it to `assets.dispose` when the target is terminal.
                Route::put('/assets/{asset:uuid}/status', [AssetActionController::class, 'changeStatus'])
                    ->middleware('can:assets.update');

                // Room transfer.
                Route::post('/assets/{asset:uuid}/transfer', [AssetActionController::class, 'transfer'])
                    ->middleware('can:assets.transfer');

                // Archive (soft delete) + restore.
                Route::middleware('can:assets.delete')->group(function () {
                    Route::delete('/assets/{asset:uuid}', [AssetController::class, 'destroy']);
                    Route::post('/assets/{asset:uuid}/restore', [AssetController::class, 'restore'])->withTrashed();

                    Route::delete('/pc-units/{pc_unit:uuid}', [PcUnitController::class, 'destroy']);
                    Route::post('/pc-units/{pc_unit:uuid}/restore', [PcUnitController::class, 'restore'])->withTrashed();
                });
            });

        /*
        |------------------------------------------------------------------
        | Ticket Management (Phase 2.6) — FR-TKT-*, FR-ASN-*
        |------------------------------------------------------------------
        | The first module where the route gate is deliberately NOT the thing
        | that separates the roles: all three hold `tickets.view`, and which
        | *rows* each may reach is decided by `TicketVisibility` — consulted by
        | the policies AND by every query object, so a ticket absent from a
        | user's list is equally unreachable by uuid (SDD DD-40).
        |
        | Read the gates below as a floor, not a fence. `can:tickets.view` says
        | "you may see tickets at all"; the policy says which.
        */
        Route::middleware('password.current')
            ->whereUuid(['ticket', 'comment', 'attachment'])
            ->group(function () {
                // Shared vocabularies — the payload itself is role-shaped.
                Route::get('/tickets/options', TicketOptionsController::class)
                    ->middleware('can:tickets.view');

                /*
                 * Requester community feed. Administrators and Teachers only —
                 * `viewFeed` refuses technicians with 403 rather than an empty
                 * list, because their surface is the assigned queue.
                 */
                Route::middleware('can:tickets.view')->group(function () {
                    Route::get('/tickets/feed', [TicketFeedController::class, 'index']);
                    Route::get('/tickets/mine', [TicketController::class, 'mine']);
                });

                // Duplicate check before submitting (FR-TKT-011) — text
                // similarity, not AI.
                Route::get('/tickets/duplicates', [TicketFeedController::class, 'duplicates'])
                    ->middleware('can:tickets.create');

                /*
                 * Technician queue. Literal segments precede the {ticket}
                 * wildcard so `/tickets/assigned` is never read as a uuid.
                 */
                Route::middleware('can:tickets.update')->group(function () {
                    Route::get('/tickets/assigned', [TechnicianQueueController::class, 'index']);
                    Route::get('/tickets/assigned/history', [TechnicianQueueController::class, 'history']);
                    Route::get('/tickets/assigned/{ticket:uuid}', [TechnicianQueueController::class, 'show']);

                    Route::post('/tickets/assigned/{ticket:uuid}/accept', [TechnicianQueueController::class, 'accept']);
                    Route::post('/tickets/assigned/{ticket:uuid}/decline', [TechnicianQueueController::class, 'decline']);
                    Route::post('/tickets/assigned/{ticket:uuid}/start', [TechnicianQueueController::class, 'start']);
                    Route::post('/tickets/assigned/{ticket:uuid}/hold', [TechnicianQueueController::class, 'hold']);
                    Route::post('/tickets/assigned/{ticket:uuid}/complete', [TechnicianQueueController::class, 'complete']);
                });

                // Report a fault.
                Route::post('/tickets', [TicketController::class, 'store'])
                    ->middleware('can:tickets.create');

                // One ticket — the projection depends on the caller (see
                // TicketController::show).
                Route::middleware('can:tickets.view')->group(function () {
                    Route::get('/tickets/feed/{ticket:uuid}', [TicketFeedController::class, 'show']);
                    Route::get('/tickets/{ticket:uuid}', [TicketController::class, 'show']);
                    Route::put('/tickets/{ticket:uuid}', [TicketController::class, 'update']);

                    // Lifecycle. The route gate only asks "may you attempt a
                    // transition"; TicketLifecycle decides which moves this
                    // actor may make, answering 422 with the reachable set.
                    Route::put('/tickets/{ticket:uuid}/status', [TicketController::class, 'changeStatus']);

                    // WP-J NOT FIXED. Not a transition (the ticket stays open),
                    // so it has its own route; FIXED is the ordinary
                    // open → resolved move through /status above.
                    Route::post('/tickets/{ticket:uuid}/not-fixed', [TicketController::class, 'reportNotFixed']);

                    Route::get('/tickets/{ticket:uuid}/attachments', [TicketParticipationController::class, 'attachments']);
                    Route::get('/tickets/attachments/{attachment:uuid}', [TicketParticipationController::class, 'downloadAttachment']);

                    /*
                     * WP-I — the Teacher AI panel. `can:tickets.view` is the
                     * floor, same as every route in this group; the controller's
                     * own `viewFull` check (TicketVisibility::canSeeFull()) is
                     * the actual gate, because this group's floor alone would
                     * let a Teacher reach another Teacher's full AI analysis
                     * through the community-card scope. `throttle:tickets`
                     * reuses the existing 20/hour/user ticket-creation limiter
                     * rather than defining a second one for the same actor.
                     */
                    Route::get('/tickets/{ticket:uuid}/ai-analysis', [TicketAiAnalysisController::class, 'show'])
                        ->middleware('throttle:tickets');
                });

                // Participation.
                Route::middleware('can:tickets.comment')->group(function () {
                    Route::get('/tickets/{ticket:uuid}/comments', [TicketParticipationController::class, 'comments']);
                    Route::post('/tickets/{ticket:uuid}/comments', [TicketParticipationController::class, 'storeComment'])
                        ->middleware('throttle:ticket-comments');
                    Route::put('/ticket-comments/{comment:uuid}', [TicketParticipationController::class, 'updateComment']);
                    Route::delete('/ticket-comments/{comment:uuid}', [TicketParticipationController::class, 'destroyComment']);
                });

                Route::post('/tickets/{ticket:uuid}/vote', [TicketParticipationController::class, 'vote'])
                    ->middleware('can:tickets.vote');

                /*
                 * Evidence. Gated on `tickets.view` — the floor — because
                 * `TicketPolicy::manageAttachments()` is what actually decides:
                 * the reporter, and the people working the ticket, may attach.
                 * Gating the route on `tickets.create` instead would make the
                 * policy's technician branch unreachable, since a Technician
                 * holds `tickets.update` but not `tickets.create` — and a
                 * technician who cannot photograph the fault they repaired is
                 * exactly the person whose evidence the record needs.
                 * `StoreTicketAttachmentRequest` and the destroy method both
                 * re-check `manageAttachments`, so the narrower rule still holds.
                 */
                Route::middleware('can:tickets.view')->group(function () {
                    Route::post('/tickets/{ticket:uuid}/attachments', [TicketParticipationController::class, 'storeAttachment']);
                    Route::delete('/tickets/attachments/{attachment:uuid}', [TicketParticipationController::class, 'destroyAttachment']);
                });
            });

        /*
        |------------------------------------------------------------------
        | Interactive Floor Plan (Phase 2.8 / WP-C map, WP-D placement,
        | WP-E real-time, WP-F layout persistence) — ADMINISTRATOR ONLY
        |------------------------------------------------------------------
        | Gated by the **policy** ability `viewAny` on RoomLayout, never by
        | `can:floorplan.view`. `Gate::before` answers any ability whose string is
        | a permission in the user's set, and a per-user grant (FR-USER-010) can
        | put `floorplan.*` in a Technician's or Teacher's — so a permission-string
        | gate would open for them. `viewAny` matches no permission name, so the
        | policy decides, and it requires the Administrator role as well.
        |
        | `{room}` is deliberately a plain string, not `{room:uuid}`: implicit
        | binding runs before this gate, and a bound model would turn an unknown
        | uuid into a 404 for callers who are about to be refused with a 403. The
        | service resolves it after authorizing. `whereUuid` keeps numeric ids and
        | junk a clean 404 at the router (NFR-SEC-001).
        */
        Route::middleware(['password.current', 'can:viewAny,'.RoomLayout::class])
            ->prefix('admin')
            // One `where*` call per group: a second one on the registrar
            // *replaces* the first rather than merging, so per-route patterns
            // (like `version` below) go on the route itself.
            ->whereUuid(['room', 'pcUnit'])
            ->group(function () {
                Route::get('/floor-plan/rooms/{room}', [FloorPlanController::class, 'showRoom']);

                /*
                | Placing / moving a PC unit (WP-D) — FR-FP-003/009.
                |
                | Writes additionally need the policy ability `manage` on
                | FloorPlanPosition — again never `can:floorplan.manage`. The
                | layout is addressed as (room, version) through its room, and
                | the unit through the layout's room, all resolved after
                | authorization inside `PlacePcUnit`; that scoping is what keeps
                | this route from ever re-homing a machine (an asset transfer).
                */
                Route::patch(
                    '/floor-plan/rooms/{room}/layouts/{version}/positions/{pcUnit}',
                    [FloorPlanPositionController::class, 'update'],
                )->whereNumber('version')->middleware('can:manage,'.FloorPlanPosition::class);

                /*
                | Layout persistence (WP-F) — FR-FP-001/008.
                |
                | Same gate as placement, on RoomLayout instead of
                | FloorPlanPosition: `can:manage,RoomLayout` — never
                | `can:floorplan.manage`. Both actions re-check it themselves
                | (RoomLayoutService::authorizeManage) before resolving
                | anything, so this middleware is a floor, not the only gate.
                */
                Route::post(
                    '/floor-plan/rooms/{room}/layouts',
                    [RoomLayoutController::class, 'store'],
                )->middleware('can:manage,'.RoomLayout::class);

                Route::post(
                    '/floor-plan/rooms/{room}/layouts/{version}/activate',
                    [RoomLayoutController::class, 'activate'],
                )->whereNumber('version')->middleware('can:manage,'.RoomLayout::class);
            });

        /*
        |------------------------------------------------------------------
        | Predictive-maintenance findings (WP-M) — ADMINISTRATOR ONLY
        |------------------------------------------------------------------
        | SRS FR-AI-011. Gated by the **policy** abilities `viewAny` / `manage`
        | on AiPrediction, never by `can:predictions.view` — the same reasoning,
        | and the same trap, as the floor plan above: `Gate::before` answers any
        | ability whose string is a permission in the user's set, so a per-user
        | grant of `predictions.*` to a Technician would open a permission-string
        | gate. The policy requires the Administrator role as well.
        |
        | `{prediction}` is a plain string, not `{prediction:uuid}`: implicit
        | binding runs before this gate, and a bound model would answer an
        | unauthorized caller with a 404 for an unknown uuid and a 403 for a real
        | one — an existence oracle. The controller resolves it after
        | authorizing. `whereUuid` keeps junk a clean 404 at the router.
        |
        | Confirm and dismiss are named PATCHes with no `status` in the body: the
        | decision is the endpoint. Neither does anything but record the verdict
        | (FR-AI-032 — AI output is advisory).
        */
        Route::middleware(['password.current', 'can:viewAny,'.AiPrediction::class])
            ->prefix('admin')
            ->whereUuid(['prediction'])
            ->group(function () {
                Route::get('/predictions', [AiPredictionController::class, 'index']);
                Route::get('/predictions/{prediction}', [AiPredictionController::class, 'show']);

                Route::patch('/predictions/{prediction}/confirm', [AiPredictionController::class, 'confirm'])
                    ->middleware('can:manage,'.AiPrediction::class);
                Route::patch('/predictions/{prediction}/dismiss', [AiPredictionController::class, 'dismiss'])
                    ->middleware('can:manage,'.AiPrediction::class);
            });

        /*
        |------------------------------------------------------------------
        | Ticket administration (Phase 2.6) — ADMINISTRATOR ONLY
        |------------------------------------------------------------------
        | `can:tickets.view` cannot close this surface — every role holds it —
        | so each method additionally authorizes `viewAdministrative`, which is
        | role-gated. The route prefix is a convention here, not the control.
        */
        Route::middleware('password.current')
            ->prefix('admin')
            ->whereUuid(['ticket'])
            ->group(function () {
                Route::middleware('can:tickets.view')->group(function () {
                    Route::get('/tickets/dashboard', [TicketDirectoryController::class, 'dashboard']);
                    Route::get('/tickets', [TicketDirectoryController::class, 'index']);
                    Route::get('/tickets/{ticket:uuid}', [TicketDirectoryController::class, 'show'])->withTrashed();
                });

                Route::middleware('can:tickets.update')->group(function () {
                    Route::put('/tickets/{ticket:uuid}/priority', [TicketDirectoryController::class, 'changePriority']);
                    Route::put('/tickets/{ticket:uuid}/duplicate', [TicketDirectoryController::class, 'markDuplicate']);
                });

                // Assignment. Withdrawn from the Technician role baseline, but
                // still per-user grantable — FR-ASN-001's "(and permitted
                // Technicians)" deputization path.
                Route::post('/tickets/{ticket:uuid}/assign', [TicketAssignmentController::class, 'store'])
                    ->middleware('can:tickets.assign');
            });

        /*
        |------------------------------------------------------------------
        | Maintenance (Phase 2.7) — FR-MNT-001..008/010/011
        |------------------------------------------------------------------
        | The second module whose route gate does NOT separate the roles:
        | `maintenance.*` is seeded to Administrators and Technicians alike
        | (SRS §8.4), and which *rows* each may reach is decided by
        | `MaintenanceVisibility` — consulted by the policy AND by every query
        | object, so a record absent from a technician's queue is equally
        | unreachable by uuid (SDD DD-55, on the DD-40 pattern).
        |
        | Read the gates below as a floor, not a fence. `can:maintenance.view`
        | says "you may do maintenance at all"; the policy says whose.
        |
        | A Teacher holds no `maintenance.*` permission, so every route here is
        | a 403 for them before any policy runs — the Locations/Assets stance,
        | reached by a different mechanism.
        */
        Route::middleware('password.current')
            ->whereUuid(['record', 'image'])
            ->group(function () {
                // Shared vocabularies — the payload itself is role-shaped.
                Route::get('/maintenance/options', MaintenanceOptionsController::class)
                    ->middleware('can:maintenance.view');

                /*
                 * A technician's own work. Literal segments precede the
                 * {record} wildcard so `/maintenance/history` is never read as
                 * a uuid.
                 */
                Route::middleware('can:maintenance.view')->group(function () {
                    Route::get('/maintenance', [MaintenanceQueueController::class, 'index']);
                    Route::get('/maintenance/history', [MaintenanceQueueController::class, 'history']);
                    Route::get('/maintenance/scheduled', [MaintenanceQueueController::class, 'scheduled']);

                    Route::get('/maintenance/{record:uuid}', [MaintenanceRecordController::class, 'show']);
                    Route::get('/maintenance/{record:uuid}/audit', [MaintenanceRecordController::class, 'audit']);
                });

                // Open a record. Corrective and preventive are the same write
                // path with a different type (FR-MNT-001/002).
                Route::post('/maintenance', [MaintenanceRecordController::class, 'store'])
                    ->middleware('can:maintenance.create');

                /*
                 * Writes. The gate is the floor; MaintenanceRecordPolicy decides
                 * whose record it is, and MaintenanceLifecycle decides which
                 * moves are legal — the route knows neither.
                 */
                Route::middleware('can:maintenance.update')->group(function () {
                    Route::put('/maintenance/{record:uuid}', [MaintenanceRecordController::class, 'update']);
                    Route::put('/maintenance/{record:uuid}/status', [MaintenanceRecordController::class, 'changeStatus']);
                });

                /*
                 * Reassignment is administrator-only, but there is no
                 * `maintenance.assign` permission in the seeded matrix (SRS
                 * §8.4) and WP-2.6 does not invent one — so the gate here is the
                 * ordinary update floor and `MaintenanceRecordPolicy::reassign`
                 * is the actual control.
                 */
                Route::post('/maintenance/{record:uuid}/reassign', [MaintenanceRecordController::class, 'reassign'])
                    ->middleware('can:maintenance.update');

                /*
                 * Checklist, evidence, notes and replacements — the parts of
                 * doing the job.
                 *
                 * All four are gated on `maintenance.update` (the floor) while
                 * the policy decides: the record must be this caller's and the
                 * visit must still be open. Every child is addressed **through**
                 * its record, so an enumerated child identifier can only reach a
                 * row belonging to a record the caller was already authorized
                 * for.
                 */
                Route::middleware('can:maintenance.view')->group(function () {
                    Route::get('/maintenance/{record:uuid}/checklist', [MaintenanceChecklistController::class, 'index']);
                    Route::get('/maintenance/{record:uuid}/evidence', [MaintenanceEvidenceController::class, 'index']);
                    Route::get('/maintenance/{record:uuid}/notes', [MaintenanceNoteController::class, 'index']);
                    Route::get('/maintenance/{record:uuid}/replacements', [HardwareReplacementController::class, 'index']);

                    // Streamed from a private disk; the owning record's policy
                    // is re-checked on every download, and the image must
                    // belong to that record or the route 404s.
                    Route::get('/maintenance/{record:uuid}/evidence/{image:uuid}', [MaintenanceEvidenceController::class, 'download']);
                });

                Route::middleware('can:maintenance.update')->group(function () {
                    Route::put('/maintenance/{record:uuid}/checklist/{item}', [MaintenanceChecklistController::class, 'update'])
                        ->whereNumber('item');

                    Route::post('/maintenance/{record:uuid}/evidence', [MaintenanceEvidenceController::class, 'store']);
                    Route::delete('/maintenance/{record:uuid}/evidence/{image:uuid}', [MaintenanceEvidenceController::class, 'destroy']);

                    Route::post('/maintenance/{record:uuid}/notes', [MaintenanceNoteController::class, 'store']);
                    Route::post('/maintenance/{record:uuid}/replacements', [HardwareReplacementController::class, 'store']);
                });

                // Archive + restore. A technician holds `maintenance.delete`, so
                // the policy is what caps them at their own untouched work.
                Route::middleware('can:maintenance.delete')->group(function () {
                    Route::delete('/maintenance/{record:uuid}', [MaintenanceRecordController::class, 'destroy']);
                    Route::post('/maintenance/{record:uuid}/restore', [MaintenanceRecordController::class, 'restore'])
                        ->withTrashed();
                });
            });

        /*
        |------------------------------------------------------------------
        | Maintenance administration (Phase 2.7) — ADMINISTRATOR ONLY
        |------------------------------------------------------------------
        | `can:maintenance.view` cannot close this surface — a Technician holds
        | it — so each method additionally authorizes `viewAdministrative`,
        | which is role-gated. The route prefix is a convention here, not the
        | control.
        */
        Route::middleware('password.current')
            ->prefix('admin')
            ->whereUuid(['record'])
            ->group(function () {
                Route::middleware('can:maintenance.view')->group(function () {
                    Route::get('/maintenance/dashboard', [MaintenanceDirectoryController::class, 'dashboard']);
                    Route::get('/maintenance', [MaintenanceDirectoryController::class, 'index']);
                });
            });

        /*
        |------------------------------------------------------------------
        | Work support request management (WP-2.6b Stage E) — ADMINISTRATOR
        |------------------------------------------------------------------
        | FR-WSR-005/006/007/008/010.
        |
        | Same shape and same reasoning as maintenance administration above:
        | `can:maintenance.view` is a floor a Technician also clears, so every
        | method additionally authorizes through `WorkSupportVisibility`, which
        | is role-gated. The prefix is a convention, not the control.
        |
        | **Three decisions, three endpoints, and no `PATCH /status` anywhere.**
        | FR-WSR-004 requires transitions to come from the map rather than from
        | the client, so the decision *is* the route and the payload carries
        | only that decision's evidence — a date to approve, a reason to
        | discuss, an explanation to decline.
        */
        Route::middleware(['password.current', 'can:maintenance.view'])
            ->prefix('admin')
            ->group(function () {
                Route::get('/work-support-requests', [WorkSupportRequestDecisionController::class, 'index']);
                Route::get('/work-support-requests/{workSupportRequest:uuid}', [WorkSupportRequestDecisionController::class, 'show']);

                Route::post('/work-support-requests/{workSupportRequest:uuid}/approve', [WorkSupportRequestDecisionController::class, 'approve']);
                Route::post('/work-support-requests/{workSupportRequest:uuid}/request-clarification', [WorkSupportRequestDecisionController::class, 'requestClarification']);
                Route::post('/work-support-requests/{workSupportRequest:uuid}/decline', [WorkSupportRequestDecisionController::class, 'decline']);
                Route::post('/work-support-requests/{workSupportRequest:uuid}/close', [WorkSupportRequestDecisionController::class, 'close']);
            });

        /*
        |------------------------------------------------------------------
        | Role dashboards (Phase 2.4) — FR-DSH-001/003/004/005/007
        |------------------------------------------------------------------
        | One endpoint for every role: DashboardService picks the layout from the
        | caller's role and the *content* from their effective permissions, so a
        | technician never receives administrator figures.
        */
        Route::middleware('password.current')->group(function () {
            Route::get('/dashboard/widgets', DashboardController::class);
        });
    });
});

/*
 * Infrastructure smoke-test (not a business feature). Confirms the app
 * can reach PostgreSQL and Redis. Safe to keep; remove if undesired.
 */
Route::get('/health', HealthController::class);
