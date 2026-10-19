<?php

declare(strict_types=1);

use App\Enums\MaintenanceStatus;
use App\Models\MaintenanceRecord;
use Database\Seeders\MaintenanceTypeSeeder;
use Illuminate\Support\Facades\Cache;

/*
 * The technician's "My maintenance" cards and the lists they open (SDD DD-81,
 * D3 of the dashboard plan).
 *
 * "Mine" is the Maintenance module's own rule — assigned to me **or** opened by me
 * (FR-MNT-011, `MaintenanceVisibility::scopeOwn`). The dashboard used to count
 * `technician_id` alone, so a record a technician opened and an administrator
 * later reassigned was on their /maintenance page but missing from their card.
 */

beforeEach(function () {
    seedRbac();
    $this->seed(MaintenanceTypeSeeder::class);

    $this->travelTo(now()->startOfSecond());

    $this->technician = userWithRole('technician');
    $this->colleague = userWithRole('technician');
    $this->admin = userWithRole('administrator');

    $now = now();
    $record = fn (array $o): MaintenanceRecord => maintenanceFor($this->technician, 'corrective', $o);

    // Overdue: open and scheduled in the past.
    $this->overdue = $record(['scheduled_for' => $now->copy()->subDay()]);
    $this->overdueHeld = $record(['status' => MaintenanceStatus::OnHold->value, 'scheduled_for' => $now->copy()->subHour()]);
    // Opened by me, since reassigned to a colleague — still "mine" under FR-MNT-011.
    $this->reassigned = maintenanceFor($this->colleague, 'corrective', [
        'created_by' => $this->technician->id,
        'scheduled_for' => $now->copy()->subHours(3),
    ]);

    // Due soon: now (inclusive) and exactly 7 days ahead (inclusive).
    $this->dueNow = $record(['scheduled_for' => $now->copy()]);
    $this->dueEdge = $record(['scheduled_for' => $now->copy()->addDays(7)]);
    $this->dueOutside = $record(['scheduled_for' => $now->copy()->addDays(7)->addSecond()]);

    // In progress, scheduled in the future.
    $this->working = $record([
        'status' => MaintenanceStatus::InProgress->value,
        'scheduled_for' => $now->copy()->addDays(2),
    ]);

    // Finished work and unscheduled work count toward nothing.
    $record(['status' => MaintenanceStatus::Completed->value, 'scheduled_for' => $now->copy()->subDays(3), 'completed_at' => $now]);
    $record(['status' => MaintenanceStatus::Cancelled->value, 'scheduled_for' => $now->copy()->subDays(3)]);
    $record(['scheduled_for' => null]);

    // The colleague's own record: in no one else's figures or lists.
    $this->theirs = maintenanceFor($this->colleague, 'corrective', ['scheduled_for' => $now->copy()->subDay()]);

    Cache::flush();

    $this->widgets = $this->actingAs($this->technician)
        ->getJson('/api/dashboard/widgets')->assertOk()->json('data.widgets');

    $this->listed = function (string $query): array {
        $response = $this->actingAs($this->technician)
            ->getJson("/api/maintenance?{$query}&per_page=100")->assertOk();

        return collect($response->json('data'))->pluck('id')->all();
    };
});

function maintenanceCard(array $widgets, string $key): array
{
    $widget = collect($widgets)->firstWhere('key', 'my-maintenance');

    return collect($widget['items'])->firstWhere('key', $key);
}

it('opens a list equal to each My maintenance figure', function (string $card, string $query, array $names) {
    $expected = array_map(fn (string $name): string => $this->{$name}->uuid, $names);
    $listed = ($this->listed)($query);

    expect(maintenanceCard($this->widgets, $card)['value'])->toBe(count($expected))
        ->and($listed)->toEqualCanonicalizing($expected)
        ->and($listed)->not->toContain($this->theirs->uuid);
})->with([
    'Overdue' => ['overdue', 'overdue=1', ['overdue', 'overdueHeld', 'reassigned']],
    // In-progress work scheduled inside the window is open work due soon too.
    'Due in 7 days' => ['due_soon', 'due_within=7', ['dueNow', 'dueEdge', 'working']],
    'In progress' => ['in_progress', 'status=in_progress', ['working']],
]);

it('counts a record I opened but that was reassigned (FR-MNT-011)', function () {
    expect(maintenanceCard($this->widgets, 'overdue')['value'])->toBe(3)
        ->and(($this->listed)('overdue=1'))->toContain($this->reassigned->uuid);
});

it('lists the Next maintenance panel from the same set as /maintenance/scheduled', function () {
    $panel = collect(collect($this->widgets)->firstWhere('key', 'maintenance-schedule')['rows'])->pluck('id')->all();

    $scheduled = collect(
        $this->actingAs($this->technician)->getJson('/api/maintenance/scheduled?per_page=5')->assertOk()->json('data'),
    )->pluck('id')->all();

    expect($panel)->toBe($scheduled);
});

it('carries a tone only while the figure is above zero', function () {
    $quiet = userWithRole('technician');
    Cache::flush();

    $widgets = $this->actingAs($quiet)->getJson('/api/dashboard/widgets')->assertOk()->json('data.widgets');

    foreach (['overdue', 'due_soon'] as $key) {
        $card = maintenanceCard($widgets, $key);

        expect($card['value'])->toBe(0)->and($card['tone'] ?? null)->toBeNull();
    }

    expect(maintenanceCard($this->widgets, 'overdue')['tone'])->toBe('danger');
});

/* --------------------------------------------------- the due_within filter */

it('rejects a due_within outside 1–90 or not a whole number', function (string $value) {
    $this->actingAs($this->technician)
        ->getJson("/api/maintenance?due_within={$value}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('due_within');
})->with(['zero' => '0', 'negative' => '-3', 'too large' => '91', 'fractional' => '7.5', 'text' => 'week']);

it('accepts the bounds of due_within', function (string $value) {
    $this->actingAs($this->technician)->getJson("/api/maintenance?due_within={$value}")->assertOk();
})->with(['one' => '1', 'ninety' => '90']);

it('keeps the due_within window exact at both ends', function () {
    $listed = ($this->listed)('due_within=7');

    expect($listed)->toContain($this->dueNow->uuid)
        ->toContain($this->dueEdge->uuid)
        ->not->toContain($this->dueOutside->uuid)
        // Already past: that is the Overdue card's, not "due in 7 days".
        ->not->toContain($this->overdue->uuid);
});
