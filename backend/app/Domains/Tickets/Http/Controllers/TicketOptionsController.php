<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Tag;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketPriority;
use App\Models\TicketStatus;
use App\Models\User;
use App\Support\Attachments\AttachmentSecurity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The vocabularies the ticket forms and filters need, in one round trip
 * (SRS FR-TKT-001/014).
 *
 * A single endpoint rather than five: the report form needs categories,
 * priorities, tags and the upload ceiling simultaneously, and five parallel
 * requests to open one form is the chattiness that makes a UI feel slow on the
 * modest hardware this product targets.
 *
 * **The payload is role-shaped** — every role gets exactly the fields it may
 * fill, so no form offers a control the API would refuse:
 *  - **priorities** go to everyone, because since Phase 2.9 a reporter may
 *    choose one (SDD DD-75; `StoreTicketRequest` accepts it). A requester gets
 *    the level's name, rank and colour — enough to choose — but **not** its SLA
 *    minutes: response and resolution budgets are management data, withheld
 *    from requesters everywhere else too (`TicketDetailResource::sla`);
 *  - **categories** carry `requires_detail`, so the form knows when to ask for
 *    a description of the kind of problem (SDD DD-77);
 *  - **uploads.max_kb** is the server's own per-file ceiling, so the form can
 *    refuse an oversized photo before uploading it (SDD DD-80);
 *  - the assignable-**technician** list is administrator data, empty for
 *    everyone else.
 */
class TicketOptionsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Ticket::class);

        /** @var User $user */
        $user = $request->user();

        $isStaff = in_array($user->role?->slug, ['administrator', 'technician'], true);
        $isAdministrator = $user->role?->slug === 'administrator';

        return response()->json([
            'data' => [
                'categories' => TicketCategory::query()
                    ->where('is_active', true)
                    ->orderBy('sort_order')
                    ->get()
                    ->map(fn (TicketCategory $c): array => [
                        'value' => $c->slug,
                        'label' => $c->name,
                        'description' => $c->description,
                        'requires_detail' => (bool) $c->requires_detail,
                    ])->all(),

                'statuses' => TicketStatus::query()
                    ->orderBy('sort_order')
                    ->get()
                    ->map(fn (TicketStatus $s): array => [
                        'value' => $s->slug,
                        'label' => $s->name,
                        'color' => $s->color,
                        'is_open' => $s->is_open,
                        'is_terminal' => $s->is_terminal,
                    ])->all(),

                'tags' => Tag::query()
                    ->orderBy('name')
                    ->get()
                    ->map(fn (Tag $t): array => ['value' => $t->slug, 'label' => $t->name])
                    ->all(),

                // Everyone may choose a priority when reporting (Phase 2.9);
                // only staff see what each level costs in SLA minutes. Most
                // urgent first, the order the staff controls already use — the
                // report form re-sorts its own cards ascending.
                'priorities' => TicketPriority::query()
                    ->where('is_active', true)
                    ->orderByDesc('level')
                    ->get()
                    ->map(fn (TicketPriority $p): array => [
                        'value' => $p->slug,
                        'label' => $p->name,
                        'level' => (int) $p->level,
                        'color' => $p->color,
                        ...($isStaff ? [
                            'response_minutes' => $p->response_time_minutes,
                            'resolution_minutes' => $p->resolution_time_minutes,
                        ] : []),
                    ])->all(),

                // The per-file ceiling the attachment endpoint enforces.
                'uploads' => [
                    'max_kb' => AttachmentSecurity::maxKilobytes(),
                ],

                // Administrator-only: the assignment dropdown.
                'technicians' => $isAdministrator
                    ? User::query()
                        ->whereIn('role_id', Role::query()->select('id')->whereIn('slug', ['technician', 'administrator']))
                        ->where('status', 'active')
                        ->orderBy('last_name')
                        ->orderBy('first_name')
                        ->with('role:id,slug,name')
                        ->get()
                        ->map(function (User $u): array {
                            $role = $u->getRelationValue('role');

                            return [
                                'value' => $u->uuid,
                                'label' => $u->fullName(),
                                'role' => $role instanceof Role ? (string) $role->name : '',
                            ];
                        })->all()
                    : [],
            ],
        ]);
    }
}
