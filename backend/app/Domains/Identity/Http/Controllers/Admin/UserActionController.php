<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Controllers\Admin;

use App\Domains\Identity\Actions\AdminSendPasswordReset;
use App\Domains\Identity\Actions\ForcePasswordReset;
use App\Domains\Identity\Actions\ResendApprovalEmail;
use App\Domains\Identity\Actions\ResendRejectionEmail;
use App\Domains\Identity\Actions\UnlockAccount;
use App\Domains\Identity\Http\Requests\ForcePasswordResetRequest;
use App\Domains\Identity\Http\Resources\UserDetailResource;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Discrete administrator actions on an account (SRS FR-USER admin actions):
 * force password reset, unlock, (re)send password-reset / approval / rejection
 * emails. Each authorizes, delegates to its Action (which audits), and returns a
 * message. Password reset reuses the secure token broker — the administrator
 * never sets or sees the password.
 */
class UserActionController extends Controller
{
    public function forcePasswordReset(ForcePasswordResetRequest $request, User $user, ForcePasswordReset $action): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        $user = $action->handle($user, $request->requiredFlag(), $admin, $request);
        $user->loadCount('activityAbout');

        return (new UserDetailResource($user->load(['role', 'directPermissions'])))
            ->additional(['message' => $request->requiredFlag()
                ? 'The user must reset their password at next sign-in.'
                : 'Password reset requirement cleared.'])
            ->response();
    }

    public function unlock(Request $request, User $user, UnlockAccount $action): JsonResponse
    {
        $this->authorize('unlock', $user);

        /** @var User $admin */
        $admin = $request->user();
        $action->handle($user, $admin, $request);

        return response()->json(['message' => 'Account unlocked.']);
    }

    public function sendPasswordReset(Request $request, User $user, AdminSendPasswordReset $action): JsonResponse
    {
        $this->authorize('resetPassword', $user);

        /** @var User $admin */
        $admin = $request->user();
        $action->handle($user, $admin, $request);

        return response()->json(['message' => 'Password reset email sent.']);
    }

    public function resendApproval(Request $request, User $user, ResendApprovalEmail $action): JsonResponse
    {
        $this->authorize('update', $user);

        if ($user->status !== UserStatus::Active) {
            return response()->json(['message' => 'Only an approved (active) account can receive an approval email.'], 422);
        }

        /** @var User $admin */
        $admin = $request->user();
        $action->handle($user, $admin, $request);

        return response()->json(['message' => 'Approval email resent.']);
    }

    public function resendRejection(Request $request, User $user, ResendRejectionEmail $action): JsonResponse
    {
        $this->authorize('update', $user);

        if ($user->status !== UserStatus::Rejected) {
            return response()->json(['message' => 'Only a rejected registration can receive a rejection email.'], 422);
        }

        /** @var User $admin */
        $admin = $request->user();
        $action->handle($user, $admin, $request);

        return response()->json(['message' => 'Rejection email resent.']);
    }
}
