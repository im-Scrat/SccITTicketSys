<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Controllers;

use App\Domains\Identity\Actions\RegisterApplicant;
use App\Domains\Identity\Http\Requests\RegisterRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Public registration-request endpoint (SRS FR-AUTH-013). Creates a pending
 * account that cannot authenticate until an Administrator approves it.
 */
class RegistrationController extends Controller
{
    public function store(RegisterRequest $request, RegisterApplicant $action): JsonResponse
    {
        $user = $action->handle($request->validated(), $request);

        return response()->json([
            'message' => 'Your registration request has been submitted and is awaiting administrator approval.',
            'status' => $user->status->value,
        ], 201);
    }
}
