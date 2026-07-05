<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Controllers;

use App\Domains\Identity\Http\Requests\ForgotPasswordRequest;
use App\Domains\Identity\Http\Requests\ResetPasswordRequest;
use App\Domains\Identity\Services\AuditLogger;
use App\Enums\ActivityAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Forgot-password and reset endpoints (SRS FR-AUTH-008). Uses Laravel's
 * single-use, time-limited token broker (`password_reset_tokens`). Responses to
 * the "forgot" step are always generic to avoid account enumeration. The reset
 * link points at the SPA (URL customized in AppServiceProvider).
 */
class PasswordResetController extends Controller
{
    public function sendResetLink(ForgotPasswordRequest $request, AuditLogger $audit): JsonResponse
    {
        $email = (string) $request->string('email');

        Password::sendResetLink(['email' => $email]);

        $audit->activity(
            ActivityAction::PasswordResetRequested,
            actor: User::query()->where('email', $email)->first(),
            request: $request,
            description: 'Password reset requested',
        );

        // Always generic — do not reveal whether the account exists.
        return response()->json([
            'message' => 'If an account exists for that email address, a password reset link has been sent.',
        ]);
    }

    public function reset(ResetPasswordRequest $request, AuditLogger $audit): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => $password, // 'hashed' cast hashes on set
                    'remember_token' => Str::random(60),
                    'password_changed_at' => now(),
                    'force_password_reset' => false, // requirement satisfied
                ])->save();

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [$this->messageForStatus($status)],
            ]);
        }

        $audit->activity(
            ActivityAction::PasswordResetCompleted,
            actor: User::query()->where('email', (string) $request->string('email'))->first(),
            request: $request,
            description: 'Password reset completed',
        );

        return response()->json([
            'message' => 'Your password has been reset. You can now sign in with your new password.',
        ]);
    }

    private function messageForStatus(string $status): string
    {
        return match ($status) {
            Password::INVALID_TOKEN => 'This password reset link is invalid or has expired.',
            Password::INVALID_USER => 'We could not find an account for that email address.',
            Password::RESET_THROTTLED => 'Please wait a moment before requesting another reset link.',
            default => 'We were unable to reset your password. Please try again.',
        };
    }
}
