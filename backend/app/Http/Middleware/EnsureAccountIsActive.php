<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\Identity\Exceptions\AccountNotActiveException;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Single source of truth for account-status enforcement on authenticated
 * requests (SRS FR-AUTH-016; SDD DD-19). Any authenticated user whose status is
 * not `active` (pending/rejected/suspended/inactive) is denied here with a
 * status-specific 403 — controllers never re-check status. Runs after
 * `auth:sanctum`.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && ! $user->status->canAuthenticate()) {
            throw AccountNotActiveException::fromUser($user);
        }

        return $next($request);
    }
}
