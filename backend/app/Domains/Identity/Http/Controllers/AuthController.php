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
            $request->boolean('remember'),
            $request,
        );

        return (new UserResource($user->load('role')))
            ->response()
            ->setStatusCode(200);
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
