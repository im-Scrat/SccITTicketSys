<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Controllers\Admin;

use App\Domains\Identity\Actions\SetPermissionOverrides;
use App\Domains\Identity\Http\Requests\UpdateUserPermissionsRequest;
use App\Domains\Identity\Http\Resources\PermissionResource;
use App\Domains\Identity\Http\Resources\UserDetailResource;
use App\Domains\Identity\Services\PermissionResolver;
use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Per-user permission overrides (SRS FR-USER-004/010). `index` returns the full
 * picture the matrix UI needs — the effective set, the role baseline, the
 * current grant/deny overrides, and the catalog of all permissions. `update`
 * replaces the override set via SetPermissionOverrides. The PermissionResolver
 * remains the sole authority on effective access.
 */
class UserPermissionController extends Controller
{
    public function index(Request $request, User $user, PermissionResolver $resolver): JsonResponse
    {
        $this->authorize('view', $user);

        $user->load('role.permissions');
        $overrides = $resolver->overrides($user);

        return response()->json([
            'data' => [
                'effective' => $user->effectivePermissions(),
                'role_permissions' => $user->role?->permissions->pluck('name')->values()->all() ?? [],
                'grants' => $overrides['grants'],
                'denies' => $overrides['denies'],
                'all_permissions' => PermissionResource::collection(
                    Permission::query()->orderBy('module')->orderBy('name')->get(),
                ),
            ],
        ]);
    }

    public function update(UpdateUserPermissionsRequest $request, User $user, SetPermissionOverrides $action): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        $validated = $request->validated();
        /** @var list<string> $grants */
        $grants = $validated['grants'] ?? [];
        /** @var list<string> $denies */
        $denies = $validated['denies'] ?? [];

        $user = $action->handle($user, $grants, $denies, $admin, $request);
        $user->load(['role', 'directPermissions'])->loadCount('activityAbout');

        return (new UserDetailResource($user))
            ->additional(['message' => 'Permission overrides updated.'])
            ->response();
    }
}
