<?php

declare(strict_types=1);

namespace App\Domains\Maintenance\Actions;

use App\Domains\Identity\Services\AuditLogger;
use App\Enums\ActivityAction;
use App\Models\MaintenanceChecklist;
use App\Models\MaintenanceRecord;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Tick or untick one checklist item (SRS FR-MNT-004).
 *
 * Two decisions worth stating:
 *
 * **Who and when are recorded, and they are the server's.** `completed_by` and
 * `completed_at` come from the actor and the clock, never from the payload — a
 * checklist whose completion metadata the client could set would not be
 * evidence of anything.
 *
 * **Unticking is allowed while the visit is open, and it clears the metadata.**
 * A technician who ticks the wrong line must be able to correct it, and leaving
 * a stale `completed_by` on an unticked row would say someone completed
 * something they had not. Both directions are audited, so the correction is
 * visible rather than silent. Once the record leaves its open states the policy
 * refuses the write entirely, so a completed visit's checklist is fixed.
 */
class CompleteChecklistItem
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(
        MaintenanceRecord $record,
        MaintenanceChecklist $item,
        bool $completed,
        ?string $remarks,
        User $actor,
        ?Request $request = null,
    ): MaintenanceChecklist {
        return DB::transaction(function () use ($record, $item, $completed, $remarks, $actor, $request): MaintenanceChecklist {
            $was = (bool) $item->is_completed;

            $item->forceFill([
                'is_completed' => $completed,
                'remarks' => $remarks ?? $item->remarks,
                'completed_by' => $completed ? $actor->getKey() : null,
                'completed_at' => $completed ? now() : null,
            ])->save();

            if ($was !== $completed) {
                $this->audit->activity(
                    $completed
                        ? ActivityAction::MaintenanceChecklistItemCompleted
                        : ActivityAction::MaintenanceChecklistItemReopened,
                    actor: $actor,
                    subject: $record,
                    properties: array_filter([
                        'item' => $item->item_label,
                        'required' => $item->is_required,
                        'remarks' => $remarks,
                    ], static fn (mixed $value): bool => $value !== null),
                    request: $request,
                    module: 'maintenance',
                    description: ($completed ? 'Checklist item completed: ' : 'Checklist item reopened: ').$item->item_label,
                );
            }

            return $item->refresh()->load('completedBy:id,uuid,first_name,last_name');
        });
    }
}
