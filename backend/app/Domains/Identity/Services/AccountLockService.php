<?php

declare(strict_types=1);

namespace App\Domains\Identity\Services;

use App\Enums\LoginStatus;
use App\Models\LoginHistory;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Reads and clears the login lockout for a user (SRS FR-AUTH-006; FR-USER admin
 * action "Unlock account"). Lockout is not a persisted flag — it lives in the
 * Redis RateLimiter keyed by (email, ip). Because the administrator does not know
 * which IP triggered the lockout, this service reconstructs the candidate keys
 * from the user's recent failed / locked-out `login_history` rows (plus their
 * last-login IP) within the decay window, then reads or clears those keys.
 *
 * This keeps Phase 2.2's login flow untouched while giving User Management a
 * faithful "is this account locked?" read and a real "unlock" that lets the user
 * sign in again immediately.
 */
class AccountLockService
{
    public function isLocked(User $user): bool
    {
        return $this->availableInSeconds($user) !== null;
    }

    /**
     * Seconds until the lock clears on its own, or null when not locked. When
     * several keys are locked (multiple IPs), the longest remaining wait wins.
     */
    public function availableInSeconds(User $user): ?int
    {
        $max = $this->maxAttempts();
        $wait = null;

        foreach ($this->keysFor($user) as $key) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $wait = max($wait ?? 0, RateLimiter::availableIn($key));
            }
        }

        return $wait;
    }

    /**
     * Clear every reconstructable throttle key for the user. Returns the number
     * of keys that actually held attempts (for the audit properties).
     */
    public function clear(User $user): int
    {
        $cleared = 0;

        foreach ($this->keysFor($user) as $key) {
            if (RateLimiter::attempts($key) > 0) {
                RateLimiter::clear($key);
                $cleared++;
            }
        }

        return $cleared;
    }

    /**
     * Candidate throttle keys for the user: one per distinct IP seen in recent
     * failed / locked-out attempts within the decay window, plus their last
     * successful-login IP. De-duplicated; empty IPs dropped.
     *
     * @return list<string>
     */
    public function keysFor(User $user): array
    {
        $decaySeconds = (int) config('security.login.decay_minutes', 15) * 60;

        $ips = LoginHistory::query()
            ->where('user_id', $user->getKey())
            ->whereIn('login_status', [LoginStatus::Failed->value, LoginStatus::LockedOut->value])
            ->where('login_at', '>=', now()->subSeconds($decaySeconds))
            ->pluck('ip_address')
            ->push($user->last_login_ip)
            ->filter()
            ->unique()
            ->values();

        return $ips
            ->map(fn (mixed $ip): string => LoginThrottle::key($user->email, (string) $ip))
            ->all();
    }

    private function maxAttempts(): int
    {
        return (int) config('security.login.max_attempts', 5);
    }
}
