<?php

declare(strict_types=1);

namespace App\Domains\Maintenance\Http\Controllers;

use App\Domains\Identity\Services\AuditLogger;
use App\Domains\Maintenance\Http\Resources\MaintenanceNoteResource;
use App\Enums\ActivityAction;
use App\Http\Controllers\Controller;
use App\Models\MaintenanceRecord;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Working notes on a maintenance record (SRS FR-MNT-005).
 *
 * Deliberately thin, and deliberately **append-only**: a note is what the
 * technician observed at a moment during the job, and editing or deleting one
 * afterwards would make the running account of the visit negotiable. Tickets
 * allow comment moderation because a comment is a conversation with a reporter;
 * this is a logbook.
 *
 * There is no `is_internal` flag either, and none is needed: no non-staff role
 * can reach a maintenance record at all, so the whole surface is already the
 * equivalent of an internal note.
 */
class MaintenanceNoteController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request, MaintenanceRecord $record): AnonymousResourceCollection
    {
        $this->authorize('view', $record);

        return MaintenanceNoteResource::collection(
            $record->notes()
                ->with('technician:id,uuid,first_name,last_name')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get(),
        );
    }

    public function store(Request $request, MaintenanceRecord $record): JsonResponse
    {
        $this->authorize('manageEvidence', $record);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        /** @var User $actor */
        $actor = $request->user();

        // The author is the actor, never the payload — an attributable logbook
        // is the only kind worth keeping.
        $note = $record->notes()->create([
            'technician_id' => $actor->getKey(),
            'body' => $validated['body'],
        ]);

        $this->audit->activity(
            ActivityAction::MaintenanceNoteAdded,
            actor: $actor,
            subject: $record,
            properties: ['note' => $note->getKey()],
            request: $request,
            module: 'maintenance',
            description: "Note added to {$record->title}",
        );

        return (new MaintenanceNoteResource($note->load('technician:id,uuid,first_name,last_name')))
            ->response()
            ->setStatusCode(201);
    }
}
