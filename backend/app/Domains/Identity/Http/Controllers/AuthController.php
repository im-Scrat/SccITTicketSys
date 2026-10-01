<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Controllers;

use App\Domains\Identity\Http\Requests\LoginRequest;
use App\Domains\Identity\Http\Resources\UserResource;
use App\Domains\Identity\Services\AuthService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Session authentication endpoints (SRS FR-AUTH-001/009; SDD §10).
 */
class AuthController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $user = $this->auth->login(
            (string) $request->string('email'),
            (string) $request->string('password'),
            $request,
        );

        // `lease` rides the login response body, never a cookie: it belongs in
        // sessionStorage (see EnsureTabLeaseIsValid), and only this response
        // and this tab ever see it.
        return response()->json([
            'data' => (new UserResource($user->load('role')))->resolve($request),
            'lease' => $request->session()->get('tab_lease'),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->auth->logout($request);

        return response()->json(['message' => 'You have been signed out.']);
    }

    public function me(Request $request): UserResource
    {
        /** @var User $user */
        $user = $request->user();

        return new UserResource($user->load('role'));
    }
}
