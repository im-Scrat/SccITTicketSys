<?php

declare(strict_types=1);

namespace App\Domains\Maintenance\Http\Controllers;

use App\Domains\Maintenance\Actions\CompleteChecklistItem;
use App\Domains\Maintenance\Http\Resources\MaintenanceChecklistResource;
use App\Http\Controllers\Controller;
use App\Models\MaintenanceChecklist;
use App\Models\MaintenanceRecord;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The checklist on one maintenance record (SRS FR-MNT-004).
 *
 * ── Why the item is addressed by numeric id ────────────────────────────────
 *
 * NFR-SEC-001 requires **public record identifiers** to be uuids, and every
 * addressable record in this system obeys that. A checklist line is not one: it
 * is an internal child row, and the baselined schema gives it no uuid — as it
 * gives none to `maintenance_notes`, `asset_status_history` or
 * `pc_component_installations`.
 *
 * What makes that safe here is that the id is **never resolved globally**. The
 * route nests the item inside a uuid-addressed parent, and
 * {@see resolveItem()} looks it up *through the record's own relation*, so an
 * enumerated id can only ever reach a line belonging to a record the caller had
 * already been authorized for. Guessing 500 numbers finds nothing new.
 */
class MaintenanceChecklistController extends Controller
{
    public function __construct(private readonly CompleteChecklistItem $action) {}

    /** The record's checklist, in issue order. */
    public function index(Request $request, MaintenanceRecord $record): AnonymousResourceCollection
    {
        $this->authorize('view', $record);

        return MaintenanceChecklistResource::collection(
            $record->checklists()
                ->with('completedBy:id,uuid,first_name,last_name')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(),
        );
    }

    /** Tick or untick one line. */
    public function update(Request $request, MaintenanceRecord $record, int $item): MaintenanceChecklistResource
    {
        // The write gate for the whole checklist: the visit must be open and the
        // record must be this caller's. `manageEvidence` and `update` answer the
        // same question, so ticking a box needs no weaker rule than editing the
        // record does.
        $this->authorize('update', $record);

        $validated = $request->validate([
            'is_completed' => ['required', 'boolean'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        /** @var User $user */
        $user = $request->user();

        return new MaintenanceChecklistResource(
            $this->action->handle(
                $record,
                $this->resolveItem($record, $item),
                (bool) $validated['is_completed'],
                $validated['remarks'] ?? null,
                $user,
                $request,
            ),
        );
    }

    /**
     * Resolve the item **through the record**, so a foreign id 404s rather than
     * loading a line from someone else's visit.
     */
    private function resolveItem(MaintenanceRecord $record, int $item): MaintenanceChecklist
    {
        return $record->checklists()->whereKey($item)->firstOrFail();
    }
}
