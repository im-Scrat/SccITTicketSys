<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Policies;

use App\Domains\KnowledgeBase\Services\PcPredictionAccess;
use App\Models\AiPrediction;
use App\Models\User;

/**
 * Authorization for predictive-maintenance findings (WP-M) —
 * Administrator-only, and closed by the *policy*, not a permission string.
 * See {@see PcPredictionAccess} for the `Gate::before` trap this avoids —
 * the same one WP-B's `RoomLayoutPolicy` documents.
 *
 * No per-record scoping to express: a prediction has no owner and no
 * assignment, so "absent from an administrator's list" and "unreachable by
 * uuid" are the same fact by construction for every administrator — and, for
 * anyone else, `viewAny` already refuses before a uuid is ever resolved, so
 * there is no existence oracle to leak through `view`.
 */
class AiPredictionPolicy
{
    public function __construct(private readonly PcPredictionAccess $access) {}

    public function viewAny(User $actor): bool
    {
        return $this->access->canView($actor);
    }

    public function view(User $actor, AiPrediction $prediction): bool
    {
        return $this->access->canView($actor);
    }

    /** Confirm or dismiss a finding. Accepts a class name or an instance. */
    public function manage(User $actor, ?AiPrediction $prediction = null): bool
    {
        return $this->access->canManage($actor);
    }
}
