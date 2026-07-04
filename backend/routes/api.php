<?php

use App\Domains\Identity\Http\Controllers\Admin\RegistrationReviewController;
use App\Domains\Identity\Http\Controllers\AuthController;
use App\Domains\Identity\Http\Controllers\PasswordController;
use App\Domains\Identity\Http\Controllers\PasswordResetController;
use App\Domains\Identity\Http\Controllers\RegistrationController;
use App\Http\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Identity & Access (Phase 2.2)
|--------------------------------------------------------------------------
| Sanctum SPA cookie auth (single-origin). `/sanctum/csrf-cookie` is provided
| by Sanctum. Login lockout/throttling is handled in AuthService (FR-AUTH-006);
| register/password endpoints carry per-IP throttles (NFR-SEC-009).
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
        Route::get('/user', [AuthController::class, 'me']);
        Route::put('/password', [PasswordController::class, 'update']);

        // Administrator registration-request review (FR-AUTH-014).
        Route::middleware('can:users.update')->prefix('admin')->group(function () {
            Route::get('/registrations', [RegistrationReviewController::class, 'index']);
            Route::get('/registrations/{user:uuid}', [RegistrationReviewController::class, 'show']);
            Route::post('/registrations/{user:uuid}/approve', [RegistrationReviewController::class, 'approve']);
            Route::post('/registrations/{user:uuid}/reject', [RegistrationReviewController::class, 'reject']);
        });
    });
});

/*
 * Infrastructure smoke-test (not a business feature). Confirms the app
 * can reach PostgreSQL and Redis. Safe to keep; remove if undesired.
 */
Route::get('/health', function () {
    $checks = ['app' => 'ok'];

    try {
        DB::connection()->getPdo();
        $checks['database'] = 'ok';
    } catch (Throwable $e) {
        $checks['database'] = 'error: '.$e->getMessage();
    }

    try {
        Redis::connection()->ping();
        $checks['redis'] = 'ok';
    } catch (Throwable $e) {
        $checks['redis'] = 'error: '.$e->getMessage();
    }

    $ok = ! collect($checks)->contains(fn ($v) => str_starts_with((string) $v, 'error'));

    return response()->json([
        'status' => $ok ? 'healthy' : 'degraded',
        'checks' => $checks,
        'laravel' => app()->version(),
    ], $ok ? 200 : 503);
});
