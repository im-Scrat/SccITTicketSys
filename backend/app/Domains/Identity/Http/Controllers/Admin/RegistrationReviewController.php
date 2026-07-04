<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Controllers\Admin;

use App\Domains\Identity\Actions\ApproveRegistration;
use App\Domains\Identity\Actions\RejectRegistration;
use App\Domains\Identity\Http\Requests\RejectRegistrationRequest;
use App\Domains\Identity\Http\Resources\RegistrationResource;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Administrator registration-request review queue (SRS FR-AUTH-014). Route-level
 * `can:users.update` gates the group; per-record rules (target must be pending)
 * are enforced by UserPolicy via authorize().
 */
class RegistrationReviewController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', User::class);

        $pending = User::query()
            ->with('role')
            ->where('status', UserStatus::Pending->value)
            ->orderBy('created_at')
            ->paginate(20);

        return RegistrationResource::collection($pending);
    }

    public function show(User $user): RegistrationResource
    {
        $this->authorize('view', $user);

        return new RegistrationResource($user->load('role'));
    }

    public function approve(User $user, Request $request, ApproveRegistration $action): JsonResponse
    {
        $this->authorize('approve', $user);

        $applicant = $action->handle($user, $request->user(), $request);

        return (new RegistrationResource($applicant->load('role')))
            ->additional(['message' => 'Registration approved. The applicant can now sign in.'])
            ->response();
    }

    public function reject(User $user, RejectRegistrationRequest $request, RejectRegistration $action): JsonResponse
    {
        $this->authorize('reject', $user);

        $applicant = $action->handle($user, $request->user(), $request->input('reason'), $request);

        return (new RegistrationResource($applicant->load('role')))
            ->additional(['message' => 'Registration rejected.'])
            ->response();
    }
}
