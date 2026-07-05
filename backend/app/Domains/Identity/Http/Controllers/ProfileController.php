<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Controllers;

use App\Domains\Identity\Actions\UpdateUser;
use App\Domains\Identity\Http\Requests\UpdateProfileRequest;
use App\Domains\Identity\Http\Resources\UserResource;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Self-service profile management (SRS FR-USER-008). The authenticated user is
 * both the actor and the subject; the shared UpdateUser action applies only the
 * name fields, stamps `updated_by` to self, and audits the change.
 */
class ProfileController extends Controller
{
    public function update(UpdateProfileRequest $request, UpdateUser $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user = $action->handle($user, $request->validated(), $user, $request);

        return (new UserResource($user))
            ->additional(['message' => 'Your profile has been updated.'])
            ->response();
    }
}
