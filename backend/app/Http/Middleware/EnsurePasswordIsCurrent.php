<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-side enforcement of the "force password reset" flag (SRS FR-USER admin
 * action). When an administrator requires a user to reset their password, this
 * middleware blocks the user from every protected surface until they do — the
 * flag is cleared automatically on a successful password change. It is applied
 * only to feature routes; `/user`, `/password`, and `/logout` remain reachable
 * so the user can view their principal and satisfy the requirement.
 */
class EnsurePasswordIsCurrent
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->force_password_reset) {
            return response()->json([
                'message' => 'You must change your password before continuing.',
                'code' => 'password_reset_required',
            ], 403);
        }

        return $next($request);
    }
}
