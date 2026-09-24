<?php

declare(strict_types=1);

namespace App\Domains\Maintenance\Http\Controllers;

use App\Domains\Maintenance\Services\MaintenanceVisibility;
use App\Enums\MaintenanceStatus;
use App\Enums\RepairImageType;
use App\Http\Controllers\Controller;
use App\Models\ChecklistTemplate;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The vocabularies the maintenance forms and filters need, in one round trip
 * (SRS FR-MNT-001/003/004).
 *
 * One endpoint rather than four: opening the "schedule maintenance" form needs
 * types, statuses and evidence kinds at once, and four parallel requests to
 * render one form is the chattiness that makes a UI feel slow on the modest
 * hardware this product targets.
 *
 * **The payload is role-shaped**, exactly as the ticket options are. The
 * technician list and the checklist-template catalogue are administrator data:
 * a technician cannot reassign a record and cannot edit templates, so offering
 * either would describe a control that does not exist for them.
 */
class MaintenanceOptionsController extends Controller
{
    public function __construct(private readonly MaintenanceVisibility $visibility) {}

    public function __invoke(Request $request): JsonResponse
    {
        $this->authorize('viewAny', MaintenanceRecord::class);

        /** @var User $user */
        $user = $request->user();

        $isAdministrator = $this->visibility->canSeeAdministrative($user);

        return response()->json([
            'data' => [
                'types' => MaintenanceType::query()
                    ->where('is_active', true)
                    ->orderByDesc('is_preventive')
                    ->orderBy('name')
                    ->get()
                    ->map(fn (MaintenanceType $type): array => [
                        'value' => $type->slug,
                        'label' => $type->name,
                        'description' => $type->description,
                        'is_preventive' => (bool) $type->is_preventive,
                        // The client uses this to warn, before the technician
                        // starts, that corrective work will need evidence to
                        // finish (FR-MNT-010) — the same rule the lifecycle
                        // enforces, surfaced early rather than at the wall.
                        'requires_evidence' => ! $type->is_preventive,
                        'has_checklist' => $type->default_checklist_template_id !== null,
                    ])->all(),

                'statuses' => array_map(
                    static fn (MaintenanceStatus $status): array => [
                        'value' => $status->value,
                        'label' => $status->label(),
                    ],
                    MaintenanceStatus::cases(),
                ),

                'evidence_types' => array_map(
                    static fn (RepairImageType $type): array => [
                        'value' => $type->value,
                        'label' => $type->label(),
                    ],
                    RepairImageType::cases(),
                ),

                // Administrator-only: the reassignment dropdown.
                'technicians' => $isAdministrator
                    ? User::query()
                        ->whereIn('role_id', Role::query()->select('id')->whereIn('slug', ['technician', 'administrator']))
                        ->where('status', 'active')
                        ->orderBy('last_name')
                        ->orderBy('first_name')
                        ->get()
                        ->map(fn (User $u): array => [
                            'value' => $u->uuid,
                            'label' => $u->fullName(),
                        ])->all()
                    : [],

                // Administrator-only: the catalogue a record's checklist is
                // issued from.
                'checklist_templates' => $isAdministrator
                    ? ChecklistTemplate::query()
                        ->where('is_active', true)
                        ->withCount('items')
                        ->orderBy('name')
                        ->get()
                        ->map(fn (ChecklistTemplate $template): array => [
                            'value' => $template->id,
                            'label' => $template->name,
                            'item_count' => (int) $template->items_count,
                        ])->all()
                    : [],
            ],
        ]);
    }
}
