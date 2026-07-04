<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Controllers;

use App\Domains\Identity\Actions\ChangeUserPassword;
use App\Domains\Identity\Http\Requests\ChangePasswordRequest;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Self-service password change for the authenticated user (SRS FR-AUTH-011).
 */
class PasswordController extends Controller
{
    public function update(ChangePasswordRequest $request, ChangeUserPassword $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $action->handle($user, (string) $request->string('password'), $request);

        return response()->json(['message' => 'Your password has been changed.']);
    }
}
