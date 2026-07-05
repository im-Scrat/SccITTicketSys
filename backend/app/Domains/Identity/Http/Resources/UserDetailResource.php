<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Resources;

use App\Domains\Identity\Services\AccountLockService;
use App\Domains\Identity\Services\PermissionResolver;
use App\Models\ActivityLog;
use App\Models\LoginHistory;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * The complete user profile for the detail page (SRS v1.2 FR-USER details).
 * Combines stored fields, the effective/override permission sets, and derived
 * signals (last activity, lockout, current session) that the platform computes
 * rather than persists. The controller eager-loads role / createdBy / updatedBy
 * / rejectedBy / directPermissions; the derived signals cost a few bounded
 * queries and are only used on this single-record endpoint.
 *
 * @mixin User
 */
class UserDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $overrides = $this->overrides();
        $lock = app(AccountLockService::class);
        $lockedSeconds = $lock->availableInSeconds($this->resource);

        return [
            'id' => $this->uuid,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'name' => $this->fullName(),
            'email' => $this->email,
            'employee_number' => $this->employee_number,
            'contact_number' => $this->contact_number,
            'profile_picture' => $this->profile_picture,
            'organization' => (string) config('app.name'),

            'role' => [
                'slug' => $this->role?->slug,
                'name' => $this->role?->name,
            ],
            'status' => $this->status->value,

            'effective_permissions' => $this->effectivePermissions(),
            'additional_permissions' => $overrides,

            'registration_source' => $this->registration_source,
            'registered_at' => $this->created_at?->toIso8601String(),
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'approved_at' => $this->email_verified_at?->toIso8601String(),

            'rejection' => [
                'reason' => $this->rejection_reason,
                'at' => $this->rejected_at?->toIso8601String(),
                'by' => $this->whenLoaded('rejectedBy', fn () => $this->rejectedBy?->fullName()),
            ],

            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'last_login_ip' => $this->last_login_ip,
            'last_activity_at' => $this->lastActivityAt(),
            'password_changed_at' => $this->password_changed_at?->toIso8601String(),
            'force_password_reset' => $this->force_password_reset,

            'lockout' => [
                'locked' => $lockedSeconds !== null,
                'available_in_seconds' => $lockedSeconds,
            ],

            'session' => [
                'active' => $this->sessionActive(),
            ],

            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->fullName()),
            'updated_by' => $this->whenLoaded('updatedBy', fn () => $this->updatedBy?->fullName()),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'archived_at' => $this->deleted_at?->toIso8601String(),

            'audit_count' => $this->whenCounted('activityAbout'),
        ];
    }

    /**
     * The user's grant/deny overrides, read from the pivot by the resolver.
     *
     * @return array{grants: list<string>, denies: list<string>}
     */
    private function overrides(): array
    {
        return app(PermissionResolver::class)->overrides($this->resource);
    }

    /** Most recent moment the user did anything (activity log or login). */
    private function lastActivityAt(): ?string
    {
        $lastLog = ActivityLog::query()->where('user_id', $this->id)->max('created_at');

        $candidates = array_filter([
            $this->last_login_at,
            is_string($lastLog) ? Carbon::parse($lastLog) : $lastLog,
        ]);

        if ($candidates === []) {
            return null;
        }

        /** @var Carbon $max */
        $max = collect($candidates)->max();

        return $max->toIso8601String();
    }

    /** Whether the user has an open (un-logged-out, non-idle) session. */
    private function sessionActive(): bool
    {
        $idleHours = (int) config('security.session.idle_hours', 8);

        return LoginHistory::query()
            ->where('user_id', $this->id)
            ->where('login_status', 'success')
            ->whereNull('logout_at')
            ->where('login_at', '>=', now()->subHours($idleHours))
            ->exists();
    }
}
