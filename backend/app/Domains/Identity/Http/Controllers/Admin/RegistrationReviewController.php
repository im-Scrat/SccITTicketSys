<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Controllers\Admin;

use App\Domains\Identity\Actions\ApproveRegistration;
use App\Domains\Identity\Actions\RejectRegistration;
use App\Domains\Identity\Actions\UpdateRegistration;
use App\Domains\Identity\Http\Requests\RejectRegistrationRequest;
use App\Domains\Identity\Http\Requests\UpdateRegistrationRequest;
use App\Domains\Identity\Http\Resources\RegistrationResource;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Administrator registration-request review queue (SRS FR-AUTH-014, extended in
 * v1.2 with search/pagination and edit-before-approval). Route-level
 * `can:users.update` gates the group; per-record rules (target must be pending)
 * are enforced by UserPolicy via authorize().
 */
class RegistrationReviewController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', User::class);

        $search = trim((string) $request->query('search', ''));
        $roleSlug = (string) $request->query('role', '');
        $perPage = max(1, min((int) $request->query('per_page', 20), 100));

        $pending = User::query()
            ->with('role')
            ->where('status', UserStatus::Pending->value)
            ->when($search !== '', function (Builder $query) use ($search): void {
                $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search).'%';
                $query->where(function (Builder $q) use ($term): void {
                    $q->where('first_name', 'ILIKE', $term)
                        ->orWhere('last_name', 'ILIKE', $term)
                        ->orWhere('email', 'ILIKE', $term)
                        ->orWhere('employee_number', 'ILIKE', $term)
                        ->orWhereRaw("(first_name || ' ' || last_name) ILIKE ?", [$term]);
                });
            })
            ->when($roleSlug !== '' && $roleSlug !== 'all', function (Builder $query) use ($roleSlug): void {
                $query->whereHas('role', fn (Builder $q) => $q->where('slug', $roleSlug));
            })
            ->orderBy('created_at')
            ->paginate($perPage)
            ->withQueryString();

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

    public function update(UpdateRegistrationRequest $request, User $user, UpdateRegistration $action): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        $applicant = $action->handle($user, $request->validated(), $admin, $request);

        return (new RegistrationResource($applicant->load('role')))
            ->additional(['message' => 'Registration details updated.'])
            ->response();
    }
}
