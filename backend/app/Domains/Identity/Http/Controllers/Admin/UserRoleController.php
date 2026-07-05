<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Controllers\Admin;

use App\Domains\Identity\Actions\ChangeUserRole;
use App\Domains\Identity\Http\Requests\UpdateUserRoleRequest;
use App\Domains\Identity\Http\Resources\UserDetailResource;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Change a user's role (SRS FR-USER-003). Delegates to ChangeUserRole, which
 * invalidates the effective-permission cache so access reflects the new role at
 * once. Authorization (incl. the last-administrator invariant) is in the request
 * / policy / action.
 */
class UserRoleController extends Controller
{
    public function update(UpdateUserRoleRequest $request, User $user, ChangeUserRole $action): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        $user = $action->handle($user, (string) $request->validated()['role'], $admin, $request);
        $user->load(['role', 'directPermissions'])->loadCount('activityAbout');

        return (new UserDetailResource($user))
            ->additional(['message' => 'Role updated.'])
            ->response();
    }
}
