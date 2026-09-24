<?php

declare(strict_types=1);

namespace App\Domains\Maintenance\Http\Controllers;

use App\Domains\Maintenance\Actions\RecordHardwareReplacement;
use App\Domains\Maintenance\Http\Resources\HardwareReplacementResource;
use App\Http\Controllers\Controller;
use App\Models\MaintenanceRecord;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Parts swapped during a maintenance visit (SRS FR-MNT-006, AC-MNT-006).
 *
 * Append-only, like the notes and for the same reason: a replacement is a
 * physical event, and the installation history it wrote is now part of two other
 * records' timelines. Correcting a mistake means recording the reverse swap, not
 * editing away the evidence that the first one happened.
 *
 * The validation lives here rather than in a FormRequest because it is a single
 * small payload with no cross-field rule worth a class of its own — the same
 * judgement `TicketDirectoryController::changePriority()` makes.
 */
class HardwareReplacementController extends Controller
{
    public function __construct(private readonly RecordHardwareReplacement $action) {}

    public function index(Request $request, MaintenanceRecord $record): AnonymousResourceCollection
    {
        $this->authorize('view', $record);

        return HardwareReplacementResource::collection(
            $record->hardwareReplacements()
                ->with(['oldComponent', 'newComponent', 'oldAsset', 'newAsset'])
                ->orderByDesc('replaced_at')
                ->orderByDesc('id')
                ->get(),
        );
    }

    public function store(Request $request, MaintenanceRecord $record): JsonResponse
    {
        $this->authorize('recordReplacement', $record);

        $validated = $request->validate([
            // Catalogue parts are addressed by numeric id: `hardware_components`
            // carries no public uuid, and the id only ever travels inside a form
            // payload, never in a URL (the DD-38 exception, restated).
            'old_component' => ['nullable', 'integer', 'exists:hardware_components,id'],
            'new_component' => ['nullable', 'integer', 'exists:hardware_components,id'],

            // Serialized units are addressed by uuid, like everything public.
            // Both optional: a fan or a cable is a real replacement that was
            // never a registered asset.
            'old_asset' => ['nullable', 'uuid', Rule::exists('assets', 'uuid')->whereNull('deleted_at')],
            'new_asset' => ['nullable', 'uuid', Rule::exists('assets', 'uuid')->whereNull('deleted_at')],

            // Mirrors `hardware_replacements_quantity_check` and
            // `..._warranty_check`, so a bad value is a field error rather than
            // a database exception.
            'quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'warranty_months' => ['nullable', 'integer', 'min:0', 'max:600'],
        ]);

        /** @var User $actor */
        $actor = $request->user();

        return (new HardwareReplacementResource($this->action->handle($record, $validated, $actor, $request)))
            ->response()
            ->setStatusCode(201);
    }
}
