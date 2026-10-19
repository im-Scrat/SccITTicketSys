<?php

declare(strict_types=1);

use App\Enums\AssignmentStatus;
use App\Enums\PermissionGrantType;
use Database\Seeders\TicketLookupSeeder;
use Illuminate\Support\Str;

/*
 * Phase 2.9 — technician monitoring (`/api/admin/technicians/*`).
 *
 * Administrator-only, by role AND `users.view`; a per-user grant cannot open
 * it to a technician or teacher. Figures are labelled recorded vs calculated,
 * and there is no availability figure, because nothing records one.
 */

beforeEach(function (): void {
    seedRbac();
    $this->seed(TicketLookupSeeder::class);

    $this->admin = userWithRole('administrator');
    $this->teacher = userWithRole('teacher');
    $this->technician = userWithRole('technician', ['first_name' => 'Tess', 'last_name' => 'Tech']);
});

/* ------------------------------------------------------------ access */

it('refuses a teacher the overview', function (): void {
    $this->actingAs($this->teacher)->getJson('/api/admin/technicians/overview')->assertForbidden();
});

it('refuses a technician the overview', function (): void {
    $this->actingAs($this->technician)->getJson('/api/admin/technicians/overview')->assertForbidden();
});

it('refuses a technician the overview even with a per-user users.view grant', function (): void {
    givePermission($this->technician, 'users.view', PermissionGrantType::Grant);

    $this->actingAs($this->technician)->getJson('/api/admin/technicians/overview')->assertForbidden();
});

it('refuses a teacher a technician’s detail', function (): void {
    $this->actingAs($this->teacher)->getJson("/api/admin/technicians/{$this->technician->uuid}")->assertForbidden();
});

it('refuses a technician a colleague’s detail', function (): void {
    $colleague = userWithRole('technician');

    $this->actingAs($this->technician)->getJson("/api/admin/technicians/{$colleague->uuid}")->assertForbidden();
});

it('withdraws the surface from an administrator with a per-user users.view deny', function (): void {
    givePermission($this->admin, 'users.view', PermissionGrantType::Deny);

    $this->actingAs($this->admin)->getJson('/api/admin/technicians/overview')->assertForbidden();
});

it('refuses an unauthenticated caller', function (): void {
    $this->getJson('/api/admin/technicians/overview')->assertUnauthorized();
});

it('answers 404 for a uuid that is not a technician, exactly as for a missing one', function (): void {
    $this->actingAs($this->admin)->getJson("/api/admin/technicians/{$this->teacher->uuid}")->assertNotFound();
    $this->actingAs($this->admin)->getJson('/api/admin/technicians/'.Str::uuid())->assertNotFound();
});

/* ------------------------------------------------------------ content */

it('lists technicians only, with labelled recorded and calculated figures', function (): void {
    $response = $this->actingAs($this->admin)->getJson('/api/admin/technicians/overview')->assertOk();

    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($this->technician->uuid)
        ->and($ids)->not->toContain($this->teacher->uuid)
        ->and($ids)->not->toContain($this->admin->uuid);

    $row = collect($response->json('data'))->firstWhere('id', $this->technician->uuid);
    expect(array_keys($row))->toEqualCanonicalizing(['id', 'name', 'account_status', 'recorded', 'calculated'])
        ->and($row['name'])->toBe('Tess Tech')
        ->and($response->getContent())->not->toContain('availab')
        ->and($response->getContent())->not->toContain('on_duty');
});

it('reports recorded assignment counts and calculated medians from real timestamps', function (): void {
    $assignedAt = now()->subHours(5);

    foreach ([10, 30] as $minutes) {
        $ticket = ticketFor($this->teacher);
        assign($ticket, $this->technician, AssignmentStatus::Completed)->forceFill([
            'assigned_at' => $assignedAt,
            'accepted_at' => $assignedAt->copy()->addMinutes($minutes),
            'started_at' => $assignedAt->copy()->addMinutes($minutes * 2),
            'completed_at' => $assignedAt->copy()->addMinutes($minutes * 4),
        ])->save();
    }
    assign(ticketFor($this->teacher), $this->technician, AssignmentStatus::Pending);

    $data = $this->actingAs($this->admin)->getJson("/api/admin/technicians/{$this->technician->uuid}")->assertOk()->json('data');

    expect($data['recorded']['completed_assignments'])->toBe(2)
        ->and($data['recorded']['pending'])->toBe(1)
        ->and($data['recorded']['active_assignments'])->toBe(1)
        ->and($data['calculated']['median_minutes_to_accept'])->toEqual(20.0)
        ->and($data['calculated']['median_minutes_to_start'])->toEqual(40.0)
        ->and($data['calculated']['median_minutes_to_complete'])->toEqual(80.0)
        ->and($data['active_assignments'])->toHaveCount(1);
});

it('reports no median, rather than zero, for a technician with no history', function (): void {
    $data = $this->actingAs($this->admin)->getJson("/api/admin/technicians/{$this->technician->uuid}")->assertOk()->json('data');

    expect($data['calculated']['median_minutes_to_accept'])->toBeNull()
        ->and($data['recorded']['last_activity_at'])->toBeNull();
});
