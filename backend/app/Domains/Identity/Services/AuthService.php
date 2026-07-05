<?php

declare(strict_types=1);

namespace App\Domains\Identity\Services;

use App\Domains\Identity\Exceptions\AccountNotActiveException;
use App\Domains\Identity\Notifications\AccountLocked;
use App\Enums\LoginStatus;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Authentication lifecycle (SRS FR-AUTH-001..011; SDD §10).
 *
 * Login verifies credentials FIRST, then enforces the account-status gate, so a
 * non-disclosing message is returned for bad credentials while a legitimate
 * account holder learns their status (SDD DD-19). Session fixation is defended
 * by regenerating the session on login and invalidating + regenerating the CSRF
 * token on logout. Lockout/throttling use the Redis-backed RateLimiter (DD-20).
 */
class AuthService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws ValidationException on bad credentials or lockout
     * @throws AccountNotActiveException on a valid credential for a non-active account
     */
    public function login(string $email, string $password, bool $remember, Request $request): User
    {
        $key = $this->throttleKey($email, $request);
        $maxAttempts = (int) config('security.login.max_attempts', 5);
        $decaySeconds = (int) config('security.login.decay_minutes', 15) * 60;

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            throw $this->lockoutException($key);
        }

        $user = User::query()->where('email', $email)->first();

        if ($user === null || ! Hash::check($password, $user->password)) {
            RateLimiter::hit($key, $decaySeconds);

            // Threshold just crossed with this attempt → lock out and notify once.
            if (RateLimiter::remaining($key, $maxAttempts) <= 0) {
                $this->audit->login($user, LoginStatus::LockedOut, $request);
                $user?->notify(new AccountLocked(RateLimiter::availableIn($key)));

                throw $this->lockoutException($key);
            }

            $this->audit->login($user, LoginStatus::Failed, $request);

            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        // Credentials are valid — enforce the account-status gate before login.
        if (! $user->status->canAuthenticate()) {
            RateLimiter::clear($key);
            $this->audit->login($user, LoginStatus::Failed, $request);

            throw AccountNotActiveException::fromUser($user);
        }

        RateLimiter::clear($key);

        Auth::guard('web')->login($user, $remember);
        $request->session()->regenerate();

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        $this->audit->login($user, LoginStatus::Success, $request);

        return $user;
    }

    public function logout(Request $request): void
    {
        $user = $request->user();

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($user instanceof User) {
            $this->audit->logout($user);
        }
    }

    private function lockoutException(string $key): ValidationException
    {
        $seconds = RateLimiter::availableIn($key);
        $minutes = (int) ceil($seconds / 60);

        return ValidationException::withMessages([
            'email' => ["Too many login attempts. Please try again in {$minutes} minute(s)."],
        ])->status(429);
    }

    private function throttleKey(string $email, Request $request): string
    {
        return LoginThrottle::key($email, $request->ip());
    }
}
