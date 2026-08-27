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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The vocabularies the ticket forms and filters need, in one round trip
 * (SRS FR-TKT-001/014).
 *
 * A single endpoint rather than five: the report form needs categories, tags and
 * (for staff) priorities simultaneously, and five parallel requests to open one
 * form is the chattiness that makes a UI feel slow on the modest hardware this
 * product targets.
 *
 * **The payload is role-shaped.** A requester gets categories and tags — the
 * fields they may actually fill. Priorities and the assignable-technician list
 * are staff data: offering a teacher a priority dropdown the API would refuse
 * (`StoreTicketRequest` prohibits it) would be a form that lies about what it
 * can do.
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

                // Staff-only: a requester cannot set a priority, so offering the
                // list would describe a control that does not exist for them.
                'priorities' => $isStaff
                    ? TicketPriority::query()
                        ->where('is_active', true)
                        ->orderByDesc('level')
                        ->get()
                        ->map(fn (TicketPriority $p): array => [
                            'value' => $p->slug,
                            'label' => $p->name,
                            'level' => (int) $p->level,
                            'color' => $p->color,
                            'response_minutes' => $p->response_time_minutes,
                            'resolution_minutes' => $p->resolution_time_minutes,
                        ])->all()
                    : [],

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
