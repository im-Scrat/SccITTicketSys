<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Controllers\Admin;

use App\Domains\Identity\Http\Resources\RoleResource;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Read-only role catalog for User Management assignment UIs (SRS FR-USER-003/010).
 * Gated by the directory view permission — anyone who can manage users may see
 * the assignable roles. Role editing itself is a later (roles.manage) concern.
 */
class RoleController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', User::class);

        return RoleResource::collection(
            Role::query()->withCount('users')->orderBy('name')->get(),
        );
    }
}
