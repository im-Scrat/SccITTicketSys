<?php

declare(strict_types=1);

namespace App\Domains\WorkSupport\Http\Controllers;

use App\Domains\WorkSupport\Http\Resources\TechnicianSubmissionResource;
use App\Domains\WorkSupport\Services\TechnicianSubmissions;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WorkSupportRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * The technician's combined submission history (SRS FR-WSR-009).
 *
 *     GET /api/technician/submissions
 *
 * ── Why the route is named for the person, not the entity ─────────────────
 *
 * This feed is not a work-support surface that happens to include proof of work;
 * it is *what this technician submitted*, of which support requests are one
 * kind. Nesting it under `/work-support-requests` would have said the opposite,
 * and the first person to add a third kind would have had to move it.
 *
 * ── It takes no identifier, and cannot ─────────────────────────────────────
 *
 * There is no `{user}` parameter and no `technician_id` filter. The subject is
 * the session, so "someone else's submissions" is not a request this endpoint
 * can express — which is a stronger guarantee than validating such a parameter
 * would have been.
 *
 * An administrator opening this page sees **their own** submissions, by the same
 * rule. "Everything I submitted" must mean the same thing to both roles, or the
 * page lies to exactly one of them. The estate-wide views already exist
 * elsewhere: the maintenance directory and the support-request inbox.
 */
class TechnicianSubmissionController extends Controller
{
    public function __construct(private readonly TechnicianSubmissions $submissions) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        /*
         * The floor only. Which rows come back is decided by the two ownership
         * scopes the service composes, not by this gate — `viewAny` answers
         * "may you have a submissions page at all", which a Teacher may not.
         */
        $this->authorize('viewAny', WorkSupportRequest::class);

        $validated = $request->validate([
            'kind' => ['nullable', 'string', Rule::in(TechnicianSubmissions::KINDS)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        /** @var User $user */
        $user = $request->user();

        return TechnicianSubmissionResource::collection(
            $this->submissions
                ->paginate(
                    $user,
                    $validated['kind'] ?? null,
                    (int) ($validated['per_page'] ?? 20),
                )
                ->withQueryString(),
        );
    }
}
