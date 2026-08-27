<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Services;

use App\Domains\Identity\Services\UserMetrics;
use App\Domains\Locations\Services\LocationMetrics;
use App\Enums\AnnouncementAudience;
use App\Models\Announcement;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * Role-aware dashboard composition (SRS FR-DSH-001/003/004/005/007; SDD §27).
 *
 * Two rules define the contract, and the tests pin both:
 *
 *  1. **The role chooses the layout.** Administrator gets the cross-organization
 *     operations view, Technician the work queue, Teacher their own requests.
 *  2. **Permissions choose the content.** Every widget declares the permission it
 *     needs and is assembled only if the caller holds it, so a technician's
 *     payload can never contain user-management or audit figures even though the
 *     same endpoint serves everyone (deny-by-default, SDD §11.1).
 *
 * Widgets are plain arrays with a `type` the client renders: `kpi` (a row of
 * figures), `distribution` (labelled proportional rows — always with a tabular
 * reading, never colour alone, FR-DSH-007), `list` (a bounded table) and
 * `actions`. Per-user widget layout and reordering (FR-DSH-002/006) are not part
 * of this phase; the `dashboard_widgets` table stays reserved for them.
 *
 * The whole payload is cached for 30 seconds per user (FR-DSH-005): long enough
 * to absorb refreshes and navigation, short enough that operational numbers stay
 * honest.
 */
class DashboardService
{
    public const CACHE_TTL_SECONDS = 30;

    public function __construct(
        private readonly TicketMetrics $tickets,
        private readonly AssetMetrics $assets,
        private readonly UserMetrics $users,
        private readonly LocationMetrics $locations,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forUser(User $user): array
    {
        return Cache::remember(
            $this->cacheKey($user),
            self::CACHE_TTL_SECONDS,
            fn (): array => $this->build($user),
        );
    }

    /** Cache key for one user's dashboard payload. */
    public function cacheKey(User $user): string
    {
        return 'dashboard:widgets:'.$user->getKey();
    }

    /**
     * @return array<string, mixed>
     */
    private function build(User $user): array
    {
        // `users.role_id` is NOT NULL and the three roles are non-deletable
        // system rows (BR-01), so a user always resolves to a role.
        $role = $user->role->slug;

        $widgets = match ($role) {
            'administrator' => $this->administratorWidgets($user),
            'technician' => $this->technicianWidgets($user),
            default => $this->teacherWidgets($user),
        };

        return [
            'role' => $role,
            'generated_at' => now()->toIso8601String(),
            'widgets' => array_values(array_filter($widgets)),
        ];
    }

    /**
     * Cross-organization operations (FR-DSH-003).
     *
     * @return list<array<string, mixed>|null>
     */
    private function administratorWidgets(User $user): array
    {
        $widgets = [];

        if ($user->hasPermissionTo('tickets.view')) {
            $sla = $this->tickets->slaPosture();

            $widgets[] = [
                'key' => 'service-desk',
                'type' => 'kpi',
                'title' => 'Service desk',
                'description' => 'Open work and SLA posture right now.',
                // Live as of Phase 2.6 — the triage surface these figures
                // describe now exists.
                'href' => '/app/tickets/manage',
                'items' => [
                    ['key' => 'backlog', 'label' => 'Open backlog', 'value' => $this->tickets->openBacklog()],
                    ['key' => 'unassigned', 'label' => 'Unassigned', 'value' => $this->tickets->unassignedOpen(), 'tone' => 'warning'],
                    ['key' => 'breached', 'label' => 'SLA breached', 'value' => $sla['breached'], 'tone' => 'danger'],
                    [
                        'key' => 'at_risk',
                        'label' => 'Due within '.$sla['lead_hours'].' h',
                        'value' => $sla['at_risk'],
                        'tone' => 'warning',
                    ],
                ],
            ];

            $widgets[] = [
                'key' => 'tickets-by-status',
                'type' => 'distribution',
                'title' => 'Tickets by status',
                'unit' => 'tickets',
                'rows' => $this->tickets->byStatus(),
            ];

            $widgets[] = [
                'key' => 'open-by-priority',
                'type' => 'distribution',
                'title' => 'Open tickets by priority',
                'unit' => 'tickets',
                'rows' => $this->tickets->openByPriority(),
            ];

            $workload = $this->tickets->technicianWorkload();

            $widgets[] = [
                'key' => 'technician-workload',
                'type' => 'distribution',
                'title' => 'Technician workload',
                'description' => 'Open tickets per assignee.',
                'unit' => 'tickets',
                'empty' => 'No open tickets are assigned yet.',
                'rows' => array_map(static fn (array $row): array => [
                    'key' => $row['id'],
                    'label' => $row['name'],
                    'count' => $row['count'],
                ], $workload),
            ];
        }

        if ($user->hasPermissionTo('maintenance.view')) {
            $posture = $this->assets->maintenancePosture();

            $widgets[] = [
                'key' => 'maintenance',
                'type' => 'kpi',
                'title' => 'Maintenance',
                'description' => 'Preventive and corrective work in flight.',
                'items' => [
                    ['key' => 'overdue', 'label' => 'Overdue', 'value' => $posture['overdue'], 'tone' => 'danger'],
                    [
                        'key' => 'due_soon',
                        'label' => 'Due in '.$posture['lead_days'].' days',
                        'value' => $posture['due_soon'],
                        'tone' => 'warning',
                    ],
                    ['key' => 'in_progress', 'label' => 'In progress', 'value' => $posture['in_progress']],
                    ['key' => 'scheduled', 'label' => 'Scheduled', 'value' => $posture['scheduled']],
                ],
            ];
        }

        // Phase 2.5 — the equipment register (FR-AST-011, FR-DSH-003). Gated on
        // `assets.view`, which only Administrators hold (SDD DD-38), so these
        // cross-estate figures can never reach a technician's payload.
        if ($user->hasPermissionTo('assets.view')) {
            $summary = $this->assets->assetSummary();
            $warranty = $this->assets->warrantyExpiring();

            $widgets[] = [
                'key' => 'assets',
                'type' => 'kpi',
                'title' => 'Assets',
                'description' => 'The equipment register at a glance.',
                'href' => '/app/assets',
                'items' => [
                    ['key' => 'total', 'label' => 'Total assets', 'value' => $summary['total']],
                    ['key' => 'in_service', 'label' => 'In service', 'value' => $summary['in_service']],
                    [
                        'key' => 'maintenance',
                        'label' => 'Maintenance',
                        'value' => $summary['maintenance'],
                        'tone' => $summary['maintenance'] > 0 ? 'warning' : null,
                    ],
                    [
                        'key' => 'out_of_service',
                        'label' => 'Out of service',
                        'value' => $summary['out_of_service'],
                        'tone' => $summary['out_of_service'] > 0 ? 'danger' : null,
                    ],
                ],
            ];

            $widgets[] = [
                'key' => 'assets-by-status',
                'type' => 'distribution',
                'title' => 'Assets by status',
                'unit' => 'assets',
                'rows' => $this->assets->assets()['by_status'],
            ];

            $widgets[] = [
                'key' => 'assets-by-building',
                'type' => 'distribution',
                'title' => 'Assets by building',
                'unit' => 'assets',
                'empty' => 'No assets are placed in a building yet.',
                'rows' => $this->assets->byBuilding(6),
            ];

            $widgets[] = [
                'key' => 'assets-by-room',
                'type' => 'distribution',
                'title' => 'Assets by room',
                'unit' => 'assets',
                'empty' => 'No assets are placed in a room yet.',
                'rows' => $this->assets->byRoom(6),
            ];

            $widgets[] = [
                'key' => 'warranty-expiring',
                'type' => 'list',
                'title' => 'Warranty expiring',
                'description' => 'Cover lapsing within '.$warranty['days'].' days.',
                'empty' => 'No warranties lapse in the next '.$warranty['days'].' days.',
                'columns' => ['Asset', 'Room', 'Expires'],
                'rows' => array_map(static fn (array $item): array => [
                    'id' => $item['id'],
                    'cells' => [
                        $item['asset_tag'].' · '.$item['name'],
                        $item['room'] ?? '—',
                        $item['warranty_expiration'],
                    ],
                    'timestamp_cell' => 2,
                ], $warranty['items']),
            ];

            $widgets[] = [
                'key' => 'assets-recently-added',
                'type' => 'list',
                'title' => 'Recently added assets',
                'empty' => 'Nothing has been added to the register yet.',
                'columns' => ['Asset', 'Status', 'Added'],
                'rows' => array_map(static fn (array $item): array => [
                    'id' => $item['id'],
                    'cells' => [
                        $item['asset_tag'].' · '.$item['name'],
                        $item['status_label'],
                        $item['at'],
                    ],
                    'timestamp_cell' => 2,
                ], $this->assets->recentlyAdded()),
            ];
        }

        if ($user->hasPermissionTo('inventory.view')) {
            $lowStock = $this->assets->lowStock();

            $widgets[] = [
                'key' => 'inventory',
                'type' => 'kpi',
                'title' => 'Inventory',
                'items' => [
                    ['key' => 'low_stock', 'label' => 'Low stock', 'value' => $lowStock['count'], 'tone' => 'warning'],
                ],
            ];

            $widgets[] = [
                'key' => 'low-stock',
                'type' => 'list',
                'title' => 'Reorder soon',
                'description' => 'Consumables at or below their reorder level.',
                'empty' => 'All consumables are above their reorder level.',
                'columns' => ['Item', 'On hand', 'Reorder at'],
                'rows' => array_map(static fn (array $item): array => [
                    'id' => $item['id'],
                    'cells' => [
                        $item['name'],
                        $item['quantity_on_hand'].' '.$item['unit_of_measure'],
                        (string) $item['reorder_level'],
                    ],
                ], $lowStock['items']),
            ];
        }

        if ($user->hasPermissionTo('locations.view')) {
            $summary = $this->locations->summary();
            $occupancy = $this->locations->occupancy();

            $widgets[] = [
                'key' => 'estate',
                'type' => 'kpi',
                'title' => 'Estate',
                'description' => 'The spatial model everything else is placed in.',
                'href' => '/app/locations',
                'items' => [
                    ['key' => 'buildings', 'label' => 'Buildings', 'value' => $summary['buildings']],
                    ['key' => 'rooms', 'label' => 'Rooms', 'value' => $summary['rooms']],
                    [
                        'key' => 'unplaced',
                        'label' => 'Unplaced PCs',
                        'value' => $occupancy['pc_units_unplaced'],
                        'tone' => $occupancy['pc_units_unplaced'] > 0 ? 'warning' : null,
                    ],
                ],
            ];
        }

        if ($user->hasPermissionTo('users.view')) {
            $summary = $this->users->summary();

            $widgets[] = [
                'key' => 'people',
                'type' => 'kpi',
                'title' => 'People',
                'href' => '/app/users',
                'items' => [
                    ['key' => 'active', 'label' => 'Active accounts', 'value' => $summary['active']],
                    [
                        'key' => 'pending',
                        'label' => 'Pending approval',
                        'value' => $summary['pending'],
                        'tone' => $summary['pending'] > 0 ? 'warning' : null,
                        'href' => '/app/registrations',
                    ],
                    ['key' => 'suspended', 'label' => 'Suspended', 'value' => $summary['suspended']],
                ],
            ];
        }

        if ($user->hasPermissionTo('system.audit.view')) {
            $widgets[] = [
                'key' => 'recent-activity',
                'type' => 'list',
                'title' => 'Recent administrative activity',
                'empty' => 'No administrative activity recorded yet.',
                'columns' => ['Action', 'Actor', 'When'],
                'rows' => array_map(static fn (array $entry): array => [
                    'id' => ($entry['action'] ?? '').'-'.($entry['at'] ?? ''),
                    'cells' => [
                        $entry['description'] ?? $entry['label'] ?? '—',
                        $entry['actor']['name'] ?? 'System',
                        $entry['at'],
                    ],
                    'timestamp_cell' => 2,
                ], $this->users->recentActions(6)),
            ];
        }

        $widgets[] = $this->announcements($user);

        return $widgets;
    }

    /**
     * The technician's queue and workload (FR-DSH-004).
     *
     * @return list<array<string, mixed>|null>
     */
    private function technicianWidgets(User $user): array
    {
        $widgets = [];

        if ($user->hasPermissionTo('tickets.view')) {
            $sla = $this->tickets->slaPosture(technician: $user);
            $load = $this->tickets->assignmentLoad($user);

            $widgets[] = [
                'key' => 'my-work',
                'type' => 'kpi',
                'title' => 'My work',
                'description' => 'What is on your plate right now.',
                // The technician's own queue — never the directory, which they
                // cannot reach (SDD DD-40).
                'href' => '/app/tickets/assigned',
                'items' => [
                    ['key' => 'active_assignments', 'label' => 'Active assignments', 'value' => $load['active']],
                    ['key' => 'open_tickets', 'label' => 'Open tickets', 'value' => $this->tickets->openAssignedTo($user)],
                    [
                        'key' => 'at_risk',
                        'label' => 'Due within '.$sla['lead_hours'].' h',
                        'value' => $sla['at_risk'],
                        'tone' => 'warning',
                    ],
                    ['key' => 'breached', 'label' => 'SLA breached', 'value' => $sla['breached'], 'tone' => 'danger'],
                ],
            ];

            $widgets[] = [
                'key' => 'my-assignments',
                'type' => 'list',
                'title' => 'My active assignments',
                'empty' => 'Nothing is assigned to you. New assignments appear here.',
                'columns' => ['Ticket', 'Priority', 'Due'],
                'rows' => array_map(static fn (array $row): array => [
                    'id' => $row['id'],
                    'cells' => [
                        ($row['number'] ?? '—').' · '.($row['title'] ?? ''),
                        $row['priority'] ?? '—',
                        $row['resolution_due_at'],
                    ],
                    'timestamp_cell' => 2,
                ], $this->tickets->activeAssignments($user)),
            ];

            $widgets[] = [
                'key' => 'unassigned-queue',
                'type' => 'list',
                'title' => 'Unassigned queue',
                'description' => 'Open requests waiting for an owner.',
                'empty' => 'Every open request has an owner.',
                'columns' => ['Ticket', 'Priority', 'Reported'],
                'rows' => array_map(static fn (array $row): array => [
                    'id' => $row['id'],
                    'cells' => [
                        ($row['number'] ?? '—').' · '.($row['title'] ?? ''),
                        $row['priority'] ?? '—',
                        $row['created_at'],
                    ],
                    'timestamp_cell' => 2,
                ], $this->tickets->recentTickets('unassigned')),
            ];
        }

        // Phase 2.5 — a technician's *own charges*, never the estate.
        //
        // Gated on `maintenance.view` rather than `assets.view`: technicians no
        // longer hold any `assets.*` permission (SDD DD-38), and this is the
        // dashboard expression of "technicians interact with assets only through
        // assigned work". Every figure below is scoped to this user, so nothing
        // here discloses equipment they are not responsible for.
        if ($user->hasPermissionTo('maintenance.view')) {
            $assigned = $this->assets->assignedTo($user);
            $underMaintenance = $this->assets->underMaintenance($user);

            $widgets[] = [
                'key' => 'my-assets',
                'type' => 'kpi',
                'title' => 'My assets',
                'description' => 'Equipment in your care.',
                'items' => [
                    ['key' => 'assigned', 'label' => 'Assigned to me', 'value' => $assigned['count']],
                    [
                        'key' => 'maintenance',
                        'label' => 'Under maintenance',
                        'value' => $underMaintenance['count'],
                        'tone' => $underMaintenance['count'] > 0 ? 'warning' : null,
                    ],
                ],
            ];

            $widgets[] = [
                'key' => 'my-assigned-assets',
                'type' => 'list',
                'title' => 'Assets assigned to me',
                'empty' => 'No equipment is currently in your care.',
                'columns' => ['Asset', 'Status', 'Room'],
                'rows' => array_map(static fn (array $item): array => [
                    'id' => $item['id'],
                    'cells' => [
                        $item['asset_tag'].' · '.$item['name'],
                        $item['status_label'],
                        $item['room'] ?? '—',
                    ],
                ], $assigned['items']),
            ];

            $widgets[] = [
                'key' => 'my-assets-under-maintenance',
                'type' => 'list',
                'title' => 'My assets under maintenance',
                'empty' => 'None of your equipment is in repair.',
                'columns' => ['Asset', 'Status', 'Updated'],
                'rows' => array_map(static fn (array $item): array => [
                    'id' => $item['id'],
                    'cells' => [
                        $item['asset_tag'].' · '.$item['name'],
                        $item['status_label'],
                        $item['at'],
                    ],
                    'timestamp_cell' => 2,
                ], $underMaintenance['items']),
            ];

            $posture = $this->assets->maintenancePosture(technician: $user);

            $widgets[] = [
                'key' => 'my-maintenance',
                'type' => 'kpi',
                'title' => 'My maintenance',
                'items' => [
                    ['key' => 'overdue', 'label' => 'Overdue', 'value' => $posture['overdue'], 'tone' => 'danger'],
                    [
                        'key' => 'due_soon',
                        'label' => 'Due in '.$posture['lead_days'].' days',
                        'value' => $posture['due_soon'],
                        'tone' => 'warning',
                    ],
                    ['key' => 'in_progress', 'label' => 'In progress', 'value' => $posture['in_progress']],
                ],
            ];

            $widgets[] = [
                'key' => 'maintenance-schedule',
                'type' => 'list',
                'title' => 'Next maintenance',
                'empty' => 'No maintenance is scheduled for you.',
                'columns' => ['Task', 'Target', 'Scheduled'],
                'rows' => array_map(static fn (array $row): array => [
                    'id' => $row['id'],
                    'cells' => [
                        $row['title'],
                        $row['target'] ?? '—',
                        $row['scheduled_for'],
                    ],
                    'timestamp_cell' => 2,
                ], $this->assets->upcomingMaintenance(technician: $user)),
            ];
        }

        if ($user->hasPermissionTo('inventory.view')) {
            $lowStock = $this->assets->lowStock();

            $widgets[] = [
                'key' => 'low-stock',
                'type' => 'list',
                'title' => 'Reorder soon',
                'description' => 'Consumables at or below their reorder level.',
                'empty' => 'All consumables are above their reorder level.',
                'columns' => ['Item', 'On hand', 'Reorder at'],
                'rows' => array_map(static fn (array $item): array => [
                    'id' => $item['id'],
                    'cells' => [
                        $item['name'],
                        $item['quantity_on_hand'].' '.$item['unit_of_measure'],
                        (string) $item['reorder_level'],
                    ],
                ], $lowStock['items']),
            ];
        }

        $widgets[] = $this->announcements($user);

        return $widgets;
    }

    /**
     * The reporter's own requests and the one action they need (FR-DSH-001).
     *
     * @return list<array<string, mixed>|null>
     */
    private function teacherWidgets(User $user): array
    {
        $widgets = [];

        if ($user->hasPermissionTo('tickets.view')) {
            $summary = $this->tickets->reporterSummary($user);

            $widgets[] = [
                'key' => 'my-requests',
                'type' => 'kpi',
                'title' => 'My requests',
                'href' => '/app/tickets/mine',
                'items' => [
                    ['key' => 'open', 'label' => 'Open', 'value' => $summary['open']],
                    [
                        'key' => 'awaiting_confirmation',
                        'label' => 'Awaiting your confirmation',
                        'value' => $summary['awaiting_confirmation'],
                        'tone' => $summary['awaiting_confirmation'] > 0 ? 'warning' : null,
                    ],
                    ['key' => 'resolved_recently', 'label' => 'Resolved (30 days)', 'value' => $summary['resolved_recently']],
                ],
            ];

            $widgets[] = [
                'key' => 'my-recent-requests',
                'type' => 'list',
                'title' => 'Recent requests',
                'empty' => 'You have not reported anything yet. Use “Report a problem” when something breaks.',
                'columns' => ['Request', 'Status', 'Reported'],
                'rows' => array_map(static fn (array $row): array => [
                    'id' => $row['id'],
                    'cells' => [
                        ($row['number'] ?? '—').' · '.($row['title'] ?? ''),
                        $row['status']['label'] ?? '—',
                        $row['created_at'],
                    ],
                    'timestamp_cell' => 2,
                ], $this->tickets->recentTickets('reporter', $user)),
            ];
        }

        if ($user->hasPermissionTo('tickets.create')) {
            $widgets[] = [
                'key' => 'quick-actions',
                'type' => 'actions',
                'title' => 'Quick actions',
                'items' => [
                    [
                        'key' => 'report',
                        'label' => 'Report a problem',
                        'description' => 'Tell us what is broken and where.',
                        'href' => '/app/tickets/new',
                        'primary' => true,
                        // Live as of Phase 2.6 — the Tickets module now backs it.
                        'available' => true,
                    ],
                    [
                        'key' => 'browse',
                        'label' => 'See what others have reported',
                        'description' => 'Check whether your problem is already known before filing.',
                        'href' => '/app/tickets',
                        'available' => true,
                    ],
                ],
            ];
        }

        $widgets[] = $this->announcements($user);

        return $widgets;
    }

    /**
     * Active, in-window announcements for this user's audience, pinned first
     * (FR-NOT-010/011). Returns null when there is nothing to say, so the
     * dashboard does not show an empty panel.
     *
     * @return array<string, mixed>|null
     */
    private function announcements(User $user): ?array
    {
        $audience = match ($user->role->slug) {
            'administrator' => AnnouncementAudience::Admins,
            'technician' => AnnouncementAudience::Technicians,
            default => AnnouncementAudience::Teachers,
        };

        $now = now();

        $rows = Announcement::query()
            ->where('is_active', true)
            ->whereIn('audience', [AnnouncementAudience::All->value, $audience->value])
            ->where(fn (Builder $query): Builder => $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn (Builder $query): Builder => $query->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            ->orderByDesc('is_pinned')
            ->latest('created_at')
            ->orderByDesc('id')
            ->limit(3)
            ->get()
            ->map(fn (Announcement $announcement): array => [
                'id' => $announcement->uuid,
                'title' => $announcement->title,
                'content' => $announcement->content,
                'pinned' => (bool) $announcement->is_pinned,
                'at' => $announcement->created_at?->toIso8601String(),
            ])
            ->all();

        if ($rows === []) {
            return null;
        }

        return [
            'key' => 'announcements',
            'type' => 'announcements',
            'title' => 'Announcements',
            'rows' => $rows,
        ];
    }
}
