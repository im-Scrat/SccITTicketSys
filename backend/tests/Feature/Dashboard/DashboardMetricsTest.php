<?php

declare(strict_types=1);

use App\Enums\AssignmentStatus;
use App\Enums\MaintenanceStatus;
use App\Models\Consumable;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceType;
use App\Models\Room;
use App\Models\TechnicianAssignment;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketPriority;
use App\Models\TicketStatus;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    seedRbac();
    Cache::flush();
});

/**
 * @return array<string, mixed>|null
 */
function widget(array $payload, string $key): ?array
{
    foreach ($payload as $candidate) {
        if ($candidate['key'] === $key) {
            return $candidate;
        }
    }

    return null;
}

/**
 * @return array<string, int>
 */
function kpiValues(array $widget): array
{
    $values = [];

    foreach ($widget['items'] as $item) {
        $values[$item['key']] = $item['value'];
    }

    return $values;
}

function makeTicket(array $attributes = []): Ticket
{
    return Ticket::factory()->create([
        'reporter_id' => $attributes['reporter_id'] ?? User::factory()->create()->id,
        'category_id' => TicketCategory::factory(),
        'priority_id' => $attributes['priority_id'] ?? TicketPriority::factory(),
        'current_status_id' => $attributes['current_status_id'] ?? TicketStatus::factory()->create(['is_open' => true, 'is_terminal' => false])->id,
        ...$attributes,
    ]);
}

it('counts the open backlog, unassigned work and SLA posture', function () {
    $admin = userWithRole('administrator');
    $technician = userWithRole('technician');
    $open = TicketStatus::factory()->create(['is_open' => true, 'is_terminal' => false]);
    $closed = TicketStatus::factory()->create(['is_open' => false, 'is_terminal' => true]);

    // Two open (one unassigned, one assigned), one closed — closed never counts.
    makeTicket(['current_status_id' => $open->id]);
    makeTicket(['current_status_id' => $open->id, 'assigned_technician_id' => $technician->id]);
    makeTicket(['current_status_id' => $closed->id]);

    // Breached: past resolution due with nothing resolved.
    makeTicket([
        'current_status_id' => $open->id,
        'resolution_due_at' => now()->subHour(),
        'resolved_at' => null,
    ]);

    // At risk: due inside the 4 h lead window.
    makeTicket([
        'current_status_id' => $open->id,
        'resolution_due_at' => now()->addHours(2),
        'resolved_at' => null,
    ]);

    // Not at risk: comfortably in the future.
    makeTicket([
        'current_status_id' => $open->id,
        'resolution_due_at' => now()->addDays(3),
        'resolved_at' => null,
    ]);

    $widgets = $this->actingAs($admin)->getJson('/api/dashboard/widgets')->assertOk()->json('data.widgets');
    $values = kpiValues(widget($widgets, 'service-desk'));

    expect($values['backlog'])->toBe(5)
        ->and($values['unassigned'])->toBe(4)
        ->and($values['breached'])->toBe(1)
        ->and($values['at_risk'])->toBe(1);
});

it('counts a missed first response as an SLA breach', function () {
    $admin = userWithRole('administrator');
    $open = TicketStatus::factory()->create(['is_open' => true, 'is_terminal' => false]);

    makeTicket([
        'current_status_id' => $open->id,
        'response_due_at' => now()->subHours(2),
        'first_response_at' => null,
    ]);

    // Responded in time — not a breach.
    makeTicket([
        'current_status_id' => $open->id,
        'response_due_at' => now()->subHours(2),
        'first_response_at' => now()->subHours(3),
    ]);

    $widgets = $this->actingAs($admin)->getJson('/api/dashboard/widgets')->assertOk()->json('data.widgets');

    expect(kpiValues(widget($widgets, 'service-desk'))['breached'])->toBe(1);
});

it('zero-fills the status and priority distributions across the configured set', function () {
    $admin = userWithRole('administrator');
    TicketStatus::factory()->create(['name' => 'Open', 'slug' => 'open', 'is_open' => true, 'sort_order' => 1]);
    TicketStatus::factory()->create(['name' => 'On Hold', 'slug' => 'on-hold', 'is_open' => true, 'sort_order' => 2]);
    TicketPriority::factory()->create(['name' => 'Critical', 'slug' => 'critical', 'level' => 4]);
    TicketPriority::factory()->create(['name' => 'Low', 'slug' => 'low', 'level' => 1]);

    $widgets = $this->actingAs($admin)->getJson('/api/dashboard/widgets')->assertOk()->json('data.widgets');

    $statuses = collect(widget($widgets, 'tickets-by-status')['rows']);
    $priorities = collect(widget($widgets, 'open-by-priority')['rows']);

    expect($statuses->pluck('count')->sum())->toBe(0)
        ->and($statuses->firstWhere('key', 'on-hold'))->not->toBeNull()
        // Priorities read highest severity first.
        ->and($priorities->first()['key'])->toBe('critical');
});

it('reports the technician queue from that technician’s own perspective', function () {
    $technician = userWithRole('technician');
    $other = userWithRole('technician');
    $open = TicketStatus::factory()->create(['is_open' => true, 'is_terminal' => false]);

    $mine = makeTicket(['current_status_id' => $open->id, 'assigned_technician_id' => $technician->id]);
    makeTicket(['current_status_id' => $open->id, 'assigned_technician_id' => $other->id]);
    makeTicket(['current_status_id' => $open->id]);

    TechnicianAssignment::factory()->create([
        'ticket_id' => $mine->id,
        'technician_id' => $technician->id,
        'status' => AssignmentStatus::InProgress->value,
    ]);
    TechnicianAssignment::factory()->create([
        'ticket_id' => $mine->id,
        'technician_id' => $other->id,
        'status' => AssignmentStatus::Completed->value,
    ]);

    $widgets = $this->actingAs($technician)->getJson('/api/dashboard/widgets')->assertOk()->json('data.widgets');
    $values = kpiValues(widget($widgets, 'my-work'));

    expect($values['open_tickets'])->toBe(1)
        ->and($values['active_assignments'])->toBe(1);

    // The unassigned queue is shared, and shows the one ticket nobody owns.
    expect(widget($widgets, 'unassigned-queue')['rows'])->toHaveCount(1);
});

it('shows a reporter their own posture including awaiting-confirmation', function () {
    $teacher = userWithRole('teacher');
    $someoneElse = userWithRole('teacher');
    $open = TicketStatus::factory()->create(['is_open' => true, 'is_terminal' => false]);
    $resolved = TicketStatus::factory()->create(['is_open' => false, 'is_terminal' => false]);
    $closed = TicketStatus::factory()->create(['is_open' => false, 'is_terminal' => true]);

    makeTicket(['reporter_id' => $teacher->id, 'current_status_id' => $open->id]);
    makeTicket([
        'reporter_id' => $teacher->id,
        'current_status_id' => $resolved->id,
        'resolved_at' => now()->subDay(),
        'closed_at' => null,
    ]);
    makeTicket([
        'reporter_id' => $teacher->id,
        'current_status_id' => $closed->id,
        'resolved_at' => now()->subDays(2),
        'closed_at' => now()->subDay(),
    ]);
    // Another reporter's ticket must never appear in these figures.
    makeTicket(['reporter_id' => $someoneElse->id, 'current_status_id' => $open->id]);

    $widgets = $this->actingAs($teacher)->getJson('/api/dashboard/widgets')->assertOk()->json('data.widgets');
    $values = kpiValues(widget($widgets, 'my-requests'));

    expect($values['open'])->toBe(1)
        ->and($values['awaiting_confirmation'])->toBe(1)
        ->and($values['resolved_recently'])->toBe(2)
        ->and(widget($widgets, 'my-recent-requests')['rows'])->toHaveCount(3);
});

it('flags consumables at or below their reorder level', function () {
    $admin = userWithRole('administrator');

    Consumable::factory()->create(['quantity_on_hand' => 2, 'reorder_level' => 5, 'is_active' => true]);
    Consumable::factory()->create(['quantity_on_hand' => 5, 'reorder_level' => 5, 'is_active' => true]);
    Consumable::factory()->create(['quantity_on_hand' => 50, 'reorder_level' => 5, 'is_active' => true]);

    $widgets = $this->actingAs($admin)->getJson('/api/dashboard/widgets')->assertOk()->json('data.widgets');

    expect(kpiValues(widget($widgets, 'inventory'))['low_stock'])->toBe(2)
        ->and(widget($widgets, 'low-stock')['rows'])->toHaveCount(2);
});

it('separates overdue maintenance from work due inside the lead window', function () {
    $admin = userWithRole('administrator');
    $technician = userWithRole('technician');
    $type = MaintenanceType::factory()->create();

    MaintenanceRecord::factory()->create([
        'technician_id' => $technician->id,
        'maintenance_type_id' => $type->id,
        'status' => MaintenanceStatus::Scheduled->value,
        'scheduled_for' => now()->subDays(3),
    ]);
    MaintenanceRecord::factory()->create([
        'technician_id' => $technician->id,
        'maintenance_type_id' => $type->id,
        'status' => MaintenanceStatus::Scheduled->value,
        'scheduled_for' => now()->addDays(2),
    ]);
    MaintenanceRecord::factory()->create([
        'technician_id' => $technician->id,
        'maintenance_type_id' => $type->id,
        'status' => MaintenanceStatus::Scheduled->value,
        'scheduled_for' => now()->addDays(60),
    ]);
    // Completed work is history, never a posture figure.
    MaintenanceRecord::factory()->create([
        'technician_id' => $technician->id,
        'maintenance_type_id' => $type->id,
        'status' => MaintenanceStatus::Completed->value,
        'scheduled_for' => now()->subDays(5),
    ]);

    $widgets = $this->actingAs($admin)->getJson('/api/dashboard/widgets')->assertOk()->json('data.widgets');
    $values = kpiValues(widget($widgets, 'maintenance'));

    expect($values['overdue'])->toBe(1)
        ->and($values['due_soon'])->toBe(1)
        ->and($values['scheduled'])->toBe(3);
});

it('summarises the estate for an administrator', function () {
    $admin = userWithRole('administrator');
    Room::factory()->count(2)->create();

    $widgets = $this->actingAs($admin)->getJson('/api/dashboard/widgets')->assertOk()->json('data.widgets');
    $values = kpiValues(widget($widgets, 'estate'));

    expect($values['rooms'])->toBe(2)->and($values['buildings'])->toBe(2);
});

it('serves the payload from a short-lived cache', function () {
    $admin = userWithRole('administrator');
    $open = TicketStatus::factory()->create(['is_open' => true, 'is_terminal' => false]);
    makeTicket(['current_status_id' => $open->id]);

    $first = $this->actingAs($admin)->getJson('/api/dashboard/widgets')->assertOk();
    expect(kpiValues(widget($first->json('data.widgets'), 'service-desk'))['backlog'])->toBe(1);

    makeTicket(['current_status_id' => $open->id]);

    // Still the cached figure…
    $second = $this->actingAs($admin)->getJson('/api/dashboard/widgets')->assertOk();
    expect(kpiValues(widget($second->json('data.widgets'), 'service-desk'))['backlog'])->toBe(1);

    // …until the cache expires.
    Cache::flush();
    $third = $this->actingAs($admin)->getJson('/api/dashboard/widgets')->assertOk();
    expect(kpiValues(widget($third->json('data.widgets'), 'service-desk'))['backlog'])->toBe(2);
});
