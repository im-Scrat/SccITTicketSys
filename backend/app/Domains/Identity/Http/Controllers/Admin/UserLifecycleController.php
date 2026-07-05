<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Controllers\Admin;

use App\Domains\Identity\Actions\ChangeUserStatus;
use App\Domains\Identity\Http\Requests\SuspendUserRequest;
use App\Domains\Identity\Http\Resources\UserDetailResource;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Account status transitions (SRS FR-USER-007). Every transition runs through the
 * single ChangeUserStatus action, which enforces the self-lockout and
 * last-administrator invariants, invalidates the permission cache, and audits the
 * precise event. Suspend/deactivate share the stricter `suspend` policy.
 */
class UserLifecycleController extends Controller
{
    public function __construct(private readonly ChangeUserStatus $action) {}

    public function activate(Request $request, User $user): JsonResponse
    {
        $this->authorize('activate', $user);

        return $this->transition($user, UserStatus::Active, $request, 'User activated.');
    }

    public function reactivate(Request $request, User $user): JsonResponse
    {
        $this->authorize('activate', $user);

        return $this->transition($user, UserStatus::Active, $request, 'User reactivated.');
    }

    public function suspend(SuspendUserRequest $request, User $user): JsonResponse
    {
        return $this->transition($user, UserStatus::Suspended, $request, 'User suspended.', $request->input('reason'));
    }

    public function deactivate(SuspendUserRequest $request, User $user): JsonResponse
    {
        return $this->transition($user, UserStatus::Inactive, $request, 'User deactivated.', $request->input('reason'));
    }

    private function transition(User $user, UserStatus $to, Request $request, string $message, ?string $reason = null): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        $user = $this->action->handle($user, $to, $admin, $request, $reason);
        $user->loadCount('activityAbout');

        return (new UserDetailResource($user))
            ->additional(['message' => $message])
            ->response();
    }
}
