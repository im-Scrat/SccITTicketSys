<?php

declare(strict_types=1);

namespace App\Domains\Identity\Services;

use App\Enums\ActivityAction;
use App\Models\ActivityLog;
use App\Models\LoginHistory;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Aggregates the User Management dashboard metrics (SRS v1.2 FR-USER dashboard)
 * in a small, fixed set of database aggregate queries — counts are never
 * materialised into PHP collections. Feeds UserDashboardController.
 */
class UserMetrics
{
    /**
     * @return array<string, mixed>
     */
    public function dashboard(): array
    {
        return [
            'summary' => $this->summary(),
            'recent_registrations' => $this->recentRegistrations(),
            'recent_logins' => $this->recentLogins(),
            'recent_actions' => $this->recentActions(),
        ];
    }

    /**
     * @return array<string, int>
     */
    public function summary(): array
    {
        /** @var Collection<string, int> $byStatus */
        $byStatus = User::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        /** @var Collection<string, int> $byRole */
        $byRole = User::query()
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->selectRaw('roles.slug as slug, count(*) as aggregate')
            ->groupBy('roles.slug')
            ->pluck('aggregate', 'slug');

        return [
            'total' => (int) $byStatus->sum(),
            'active' => (int) ($byStatus['active'] ?? 0),
            'pending' => (int) ($byStatus['pending'] ?? 0),
            'suspended' => (int) ($byStatus['suspended'] ?? 0),
            'rejected' => (int) ($byStatus['rejected'] ?? 0),
            'inactive' => (int) ($byStatus['inactive'] ?? 0),
            'archived' => User::onlyTrashed()->count(),
            'administrators' => (int) ($byRole['administrator'] ?? 0),
            'technicians' => (int) ($byRole['technician'] ?? 0),
            'teachers' => (int) ($byRole['teacher'] ?? 0),
        ];
    }

    /**
     * Most recently created accounts (registration provenance included).
     *
     * @return list<array<string, mixed>>
     */
    public function recentRegistrations(int $limit = 6): array
    {
        return User::query()
            ->with('role')
            ->latest('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (User $user): array => [
                'id' => $user->uuid,
                'name' => $user->fullName(),
                'email' => $user->email,
                'role' => ['slug' => $user->role?->slug, 'name' => $user->role?->name],
                'status' => $user->status->value,
                'source' => $user->registration_source,
                'submitted_at' => $user->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Most recent successful sign-ins across the platform.
     *
     * @return list<array<string, mixed>>
     */
    public function recentLogins(int $limit = 6): array
    {
        return LoginHistory::query()
            ->with('user:id,uuid,first_name,last_name')
            ->whereNotNull('user_id')
            ->where('login_status', 'success')
            ->latest('login_at')
            ->limit($limit)
            ->get()
            ->map(fn (LoginHistory $row): array => [
                'user' => $row->user !== null ? [
                    'id' => $row->user->uuid,
                    'name' => $row->user->fullName(),
                ] : null,
                'ip_address' => $row->ip_address,
                'browser' => $row->browser,
                'platform' => $row->platform,
                'at' => $row->login_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Most recent administrative actions from the activity log.
     *
     * @return list<array<string, mixed>>
     */
    public function recentActions(int $limit = 8): array
    {
        return ActivityLog::query()
            ->with('user:id,uuid,first_name,last_name')
            ->latest('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (ActivityLog $log): array => [
                'actor' => $log->user !== null ? [
                    'id' => $log->user->uuid,
                    'name' => $log->user->fullName(),
                ] : null,
                'action' => $log->action,
                'label' => ActivityAction::tryFrom($log->action)?->label() ?? $log->action,
                'description' => $log->description,
                'at' => $log->created_at?->toIso8601String(),
            ])
            ->all();
    }
}
