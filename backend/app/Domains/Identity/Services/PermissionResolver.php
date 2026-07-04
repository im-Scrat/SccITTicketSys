<?php

declare(strict_types=1);

namespace App\Domains\Identity\Services;

use App\Enums\PermissionGrantType;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Computes a user's effective permission set and answers capability checks
 * (SDD DD-05 / §11.2). The set is:
 *
 *     effective = role.permissions ∪ user grants − user denies   (deny wins)
 *
 * Unknown permission ⇒ not present ⇒ deny-by-default (NFR-SEC-002). The set is
 * cached per user (Redis in production) and invalidated via forget() whenever a
 * user's role or permission overrides change.
 */
class PermissionResolver
{
    /** Cache lifetime in seconds; entries are also explicitly invalidated. */
    private const TTL = 3600;

    /**
     * @return list<string>
     */
    public function resolve(User $user): array
    {
        return Cache::remember(
            $this->cacheKey($user),
            self::TTL,
            fn (): array => $this->compute($user),
        );
    }

    public function has(User $user, string $permission): bool
    {
        return in_array($permission, $this->resolve($user), true);
    }

    public function forget(User $user): void
    {
        Cache::forget($this->cacheKey($user));
    }

    /**
     * @return list<string>
     */
    private function compute(User $user): array
    {
        $user->loadMissing('role.permissions');

        /** @var list<string> $rolePermissions */
        $rolePermissions = $user->role?->permissions->pluck('name')->all() ?? [];

        // Per-user overrides read straight from the pivot table (no hydration).
        $overrides = DB::table('user_permissions')
            ->join('permissions', 'permissions.id', '=', 'user_permissions.permission_id')
            ->where('user_permissions.user_id', $user->getKey())
            ->get(['permissions.name', 'user_permissions.grant_type']);

        $grants = [];
        $denies = [];

        foreach ($overrides as $override) {
            if ($override->grant_type === PermissionGrantType::Deny->value) {
                $denies[] = $override->name;
            } else {
                $grants[] = $override->name;
            }
        }

        $effective = array_diff(
            array_unique([...$rolePermissions, ...$grants]),
            $denies,
        );

        sort($effective);

        return $effective;
    }

    private function cacheKey(User $user): string
    {
        return "perm:user:{$user->getKey()}";
    }
}
