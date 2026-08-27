<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Raised when two administrators assign the same ticket simultaneously and the
 * database's `technician_assignments_one_active_per_ticket` partial unique index
 * rejects the loser (SRS FR-ASN-002).
 *
 * Renders **409 Conflict**, not 422 and not 500. The distinction matters: the
 * request was well-formed and the caller was entitled to make it — they simply
 * lost a race, and the honest instruction is "someone else got there first,
 * reload and look again" rather than a validation message implying they did
 * something wrong.
 *
 * Self-rendering, following `LocationInUseException` and `AssetInUseException`.
 */
class AssignmentConflictException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'Someone else assigned this ticket a moment ago. Reload to see who is on it.'
        );
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'assignment_conflict',
        ], 409);
    }
}
