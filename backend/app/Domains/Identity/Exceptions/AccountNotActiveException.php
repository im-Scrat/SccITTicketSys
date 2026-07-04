<?php

declare(strict_types=1);

namespace App\Domains\Identity\Exceptions;

use App\Enums\UserStatus;
use App\Models\User;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thrown when a user with valid identity is not permitted to access the system
 * because their account status is not `active` (SRS FR-AUTH-004/016; SDD DD-19).
 * The same exception is raised at login time (AuthService) and on every
 * authenticated request (EnsureAccountIsActive middleware), keeping account-
 * status messaging in one place. Renders a 403 with a machine-readable `code`
 * the SPA uses to route (e.g. `pending` → Awaiting-Approval page).
 */
class AccountNotActiveException extends Exception
{
    public function __construct(
        public readonly UserStatus $status,
        public readonly ?string $reason = null,
    ) {
        parent::__construct(self::messageFor($status));
    }

    public static function fromUser(User $user): self
    {
        return new self(
            $user->status,
            $user->status === UserStatus::Rejected ? $user->rejection_reason : null,
        );
    }

    public static function messageFor(UserStatus $status): string
    {
        return match ($status) {
            UserStatus::Pending => 'Your account is awaiting administrator approval.',
            UserStatus::Rejected => 'Your registration request was not approved.',
            UserStatus::Suspended => 'Your account has been suspended. Please contact your administrator.',
            UserStatus::Inactive => 'Your account is inactive. Please contact your administrator.',
            UserStatus::Active => 'Your account is active.',
        };
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => $this->status->value,
            'status' => $this->status->value,
            'reason' => $this->reason,
        ], 403);
    }
}
