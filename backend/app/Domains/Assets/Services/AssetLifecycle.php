<?php

declare(strict_types=1);

namespace App\Domains\Assets\Services;

use App\Domains\Identity\Services\AuditLogger;
use App\Enums\ActivityAction;
use App\Enums\AssetStatus;
use App\Models\Asset;
use App\Models\AssetStatusHistory;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The single write path for an asset's lifecycle status (SRS FR-AST-005).
 *
 * Two guarantees, and both are the reason this is a service rather than a line
 * in a controller:
 *
 *  1. **No transition is unaudited.** The `asset_status_history` row and the
 *     `activity_logs` row are written in **one transaction** with the status
 *     change itself, so a status can never move without leaving both trails.
 *     `asset_status_history` is the durable, queryable lifecycle record;
 *     `activity_logs` is the human-readable timeline the UI renders.
 *  2. **Only legal transitions happen.** The map lives on
 *     {@see AssetStatus::allowedTransitions()} so it is testable in isolation,
 *     and an illegal move is a 422 with the list of states that *are* reachable —
 *     not a silent no-op.
 *
 * Terminal transitions (`retired`, `disposed`) additionally require the
 * `assets.dispose` permission; that check lives in `AssetPolicy::changeStatus()`
 * because it is an authorization question, not an invariant (SDD DD-33).
 */
class AssetLifecycle
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Move an asset to a new status, recording history and audit atomically.
     *
     * @throws ValidationException when the transition is not legal
     */
    public function transition(
        Asset $asset,
        AssetStatus $target,
        User $actor,
        ?string $reason = null,
        ?Request $request = null,
    ): Asset {
        $from = $asset->status;

        if ($from === $target) {
            // Not an error — re-submitting the current status is a no-op rather
            // than a spurious history row that would pollute the timeline.
            return $asset;
        }

        $this->assertTransitionAllowed($from, $target);

        DB::transaction(function () use ($asset, $from, $target, $actor, $reason): void {
            $asset->forceFill([
                'status' => $target->value,
                'updated_by' => $actor->getKey(),
            ])->save();

            AssetStatusHistory::query()->create([
                'asset_id' => $asset->getKey(),
                'from_status' => $from->value,
                'to_status' => $target->value,
                'changed_by' => $actor->getKey(),
                'reason' => $reason,
                'created_at' => now(),
            ]);
        });

        $this->audit->activity(
            ActivityAction::AssetStatusChanged,
            actor: $actor,
            subject: $asset,
            properties: [
                'from' => $from->value,
                'from_label' => $from->label(),
                'to' => $target->value,
                'to_label' => $target->label(),
                'reason' => $reason,
            ],
            request: $request,
            module: 'assets',
            description: "Asset {$asset->asset_tag}: {$from->label()} → {$target->label()}",
        );

        return $asset->refresh();
    }

    /**
     * Record the opening state of a newly created asset, so its history starts at
     * creation rather than at the first change (FR-AST-005). `from_status` is
     * null — there was no previous state.
     */
    public function recordInitial(Asset $asset, User $actor): void
    {
        AssetStatusHistory::query()->create([
            'asset_id' => $asset->getKey(),
            'from_status' => null,
            'to_status' => $asset->status->value,
            'changed_by' => $actor->getKey(),
            'reason' => 'Asset created',
            'created_at' => now(),
        ]);
    }

    /**
     * The states this asset can currently move to — surfaced on the detail
     * endpoint so the client offers only legal choices instead of guessing.
     *
     * @return list<array{value: string, label: string, tone: string, terminal: bool}>
     */
    public function availableTransitions(Asset $asset): array
    {
        return array_map(static fn (AssetStatus $status): array => [
            'value' => $status->value,
            'label' => $status->label(),
            'tone' => $status->tone(),
            'terminal' => $status->isTerminal(),
        ], $asset->status->allowedTransitions());
    }

    /**
     * @throws ValidationException
     */
    private function assertTransitionAllowed(AssetStatus $from, AssetStatus $target): void
    {
        if ($from->canTransitionTo($target)) {
            return;
        }

        $allowed = array_map(
            static fn (AssetStatus $status): string => $status->label(),
            $from->allowedTransitions(),
        );

        $detail = $allowed === []
            ? sprintf('%s is a final state — this asset can no longer change status.', $from->label())
            : sprintf('From %s an asset can move to: %s.', $from->label(), implode(', ', $allowed));

        throw ValidationException::withMessages([
            'status' => sprintf('%s %s', $detail, "\"{$target->label()}\" is not one of them."),
        ]);
    }
}
