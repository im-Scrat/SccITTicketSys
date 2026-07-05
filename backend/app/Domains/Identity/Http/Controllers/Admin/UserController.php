<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Controllers\Admin;

use App\Domains\Identity\Actions\ArchiveUser;
use App\Domains\Identity\Actions\CreateUser;
use App\Domains\Identity\Actions\RestoreUser;
use App\Domains\Identity\Actions\UpdateUser;
use App\Domains\Identity\Http\Requests\IndexUsersRequest;
use App\Domains\Identity\Http\Requests\StoreUserRequest;
use App\Domains\Identity\Http\Requests\UpdateUserRequest;
use App\Domains\Identity\Http\Resources\UserDetailResource;
use App\Domains\Identity\Http\Resources\UserListResource;
use App\Domains\Identity\Services\UserDirectoryQuery;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * User directory CRUD (SRS v1.2 FR-USER-001/002/006). Thin controller: it
 * authorizes, delegates the write to a single-purpose Action, and returns a
 * Resource. Search / filter / sort / pagination live in UserDirectoryQuery; the
 * never-hard-delete rule is realized as archive (soft delete) + restore.
 */
class UserController extends Controller
{
    public function index(IndexUsersRequest $request, UserDirectoryQuery $directory): AnonymousResourceCollection
    {
        return UserListResource::collection($directory->paginate($request->validated()));
    }

    public function show(Request $request, User $user): UserDetailResource
    {
        $this->authorize('view', $user);

        $user->load(['role', 'createdBy', 'updatedBy', 'rejectedBy', 'directPermissions'])
            ->loadCount('activityAbout');

        return new UserDetailResource($user);
    }

    public function store(StoreUserRequest $request, CreateUser $action): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        $user = $action->handle($request->validated(), $admin, $request);

        return (new UserDetailResource($user->load(['role', 'createdBy', 'updatedBy'])))
            ->additional(['message' => 'User account created.'])
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateUserRequest $request, User $user, UpdateUser $action): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        $user = $action->handle($user, $request->validated(), $admin, $request);
        $user->load(['role', 'createdBy', 'updatedBy', 'rejectedBy', 'directPermissions'])
            ->loadCount('activityAbout');

        return (new UserDetailResource($user))
            ->additional(['message' => 'User updated.'])
            ->response();
    }

    public function destroy(Request $request, User $user, ArchiveUser $action): JsonResponse
    {
        $this->authorize('delete', $user);

        /** @var User $admin */
        $admin = $request->user();
        $action->handle($user, $admin, $request);

        return response()->json(['message' => 'User account archived.']);
    }

    public function restore(Request $request, User $user, RestoreUser $action): JsonResponse
    {
        $this->authorize('restore', $user);

        /** @var User $admin */
        $admin = $request->user();
        $user = $action->handle($user, $admin, $request);

        return (new UserDetailResource($user->load(['role', 'createdBy', 'updatedBy'])))
            ->additional(['message' => 'User account restored.'])
            ->response();
    }
}
