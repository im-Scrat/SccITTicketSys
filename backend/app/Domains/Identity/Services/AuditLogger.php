<?php

declare(strict_types=1);

namespace App\Domains\Identity\Services;

use App\Enums\ActivityAction;
use App\Enums\LoginStatus;
use App\Models\ActivityLog;
use App\Models\LoginHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Central, reusable audit writer (SRS FR-AUD-*, NFR-SEC-017; SDD §10.3).
 * Authentication attempts go to `login_history`; actor-initiated lifecycle
 * events go to `activity_logs`. Future modules reuse `activity()` for their
 * own ActivityAction cases so audit stays uniform across the platform.
 */
class AuditLogger
{
    /**
     * Record an actor-initiated activity event.
     *
     * @param  array<string, mixed>  $properties
     */
    public function activity(
        ActivityAction $action,
        ?User $actor = null,
        ?Model $subject = null,
        array $properties = [],
        ?Request $request = null,
        ?string $module = 'identity',
        ?string $description = null,
    ): ActivityLog {
        $request ??= request();

        return ActivityLog::query()->create([
            'user_id' => $actor?->getKey(),
            'action' => $action->value,
            'module' => $module,
            'description' => $description,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'properties' => $properties ?: null,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);
    }

    /**
     * Record an authentication attempt (success / failed / locked_out).
     * `user_id` is null for failed attempts against an unknown email.
     */
    public function login(?User $user, LoginStatus $status, ?Request $request = null): LoginHistory
    {
        $request ??= request();
        $agent = $request->userAgent();

        return LoginHistory::query()->create([
            'user_id' => $user?->getKey(),
            'login_at' => now(),
            'ip_address' => $request->ip(),
            'user_agent' => $agent,
            'browser' => $this->browser($agent),
            'platform' => $this->platform($agent),
            'login_status' => $status,
        ]);
    }

    /**
     * Stamp the most recent successful login row for the user with a logout time.
     */
    public function logout(User $user): void
    {
        LoginHistory::query()
            ->where('user_id', $user->getKey())
            ->where('login_status', LoginStatus::Success->value)
            ->whereNull('logout_at')
            ->latest('login_at')
            ->limit(1)
            ->update(['logout_at' => now()]);
    }

    /** Coarse browser family from a user-agent string (no external dependency). */
    private function browser(?string $ua): ?string
    {
        if ($ua === null || $ua === '') {
            return null;
        }

        return match (true) {
            str_contains($ua, 'Edg') => 'Edge',
            str_contains($ua, 'OPR'), str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'Firefox') => 'Firefox',
            str_contains($ua, 'Chrome') => 'Chrome',
            str_contains($ua, 'Safari') => 'Safari',
            default => 'Other',
        };
    }

    /** Coarse platform family from a user-agent string. */
    private function platform(?string $ua): ?string
    {
        if ($ua === null || $ua === '') {
            return null;
        }

        return match (true) {
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'iPhone'), str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Mac OS') => 'macOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'Other',
        };
    }
}
