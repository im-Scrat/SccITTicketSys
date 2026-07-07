<?php

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
use App\Http\Controllers\HealthController;
use App\Http\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Identity & Access (Phase 2.2) + User Management (Phase 2.3)
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
    });
});

/*
 * Infrastructure smoke-test (not a business feature). Confirms the app
 * can reach PostgreSQL and Redis. Safe to keep; remove if undesired.
 */
Route::get('/health', HealthController::class);
