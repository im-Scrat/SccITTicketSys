<?php

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
use App\Domains\Locations\Http\Controllers\Admin\BuildingController;
use App\Domains\Locations\Http\Controllers\Admin\FloorController;
use App\Domains\Locations\Http\Controllers\Admin\LocationAuditController;
use App\Domains\Locations\Http\Controllers\Admin\LocationDashboardController;
use App\Domains\Locations\Http\Controllers\Admin\LocationTreeController;
use App\Domains\Locations\Http\Controllers\Admin\RoomController;
use App\Domains\Locations\Http\Controllers\LocationLookupController;
use App\Domains\Tickets\Http\Controllers\Admin\TicketAssignmentController;
use App\Domains\Tickets\Http\Controllers\Admin\TicketDirectoryController;
use App\Domains\Tickets\Http\Controllers\TechnicianQueueController;
use App\Domains\Tickets\Http\Controllers\TicketController;
use App\Domains\Tickets\Http\Controllers\TicketFeedController;
use App\Domains\Tickets\Http\Controllers\TicketOptionsController;
use App\Domains\Tickets\Http\Controllers\TicketParticipationController;
use App\Http\Controllers\HealthController;
use App\Http\Middleware\AuthenticateSession;
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

                    Route::get('/tickets/{ticket:uuid}/attachments', [TicketParticipationController::class, 'attachments']);
                    Route::get('/tickets/attachments/{attachment:uuid}', [TicketParticipationController::class, 'downloadAttachment']);
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
