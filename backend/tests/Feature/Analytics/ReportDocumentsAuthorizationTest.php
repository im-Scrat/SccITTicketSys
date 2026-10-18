<?php

declare(strict_types=1);

use App\Enums\PermissionGrantType;
use App\Models\ReportExport;
use Database\Seeders\TicketLookupSeeder;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ReportFixtures;

/*
 * Phase 2.10 R3 — who may export, email and print reports (owner decision D8,
 * SDD DD-89/DD-91/DD-92).
 *
 * Every document route needs the Administrator role, reports.view **and**
 * reports.export. Proved role by role, with the traps ReportsAccess names: a
 * technician granted every report permission by hand is still refused, a
 * per-user deny of reports.export withdraws documents from a named
 * administrator, and one administrator's document is invisible to another —
 * 404, exactly like a uuid that does not exist.
 */

beforeEach(function (): void {
    seedRbac();
    $this->seed(TicketLookupSeeder::class);
    Storage::fake('local');
    $this->travelTo(ReportFixtures::local('2026-10-18 10:00')->utc());

    $this->admin = userWithRole('administrator');
    $this->technician = userWithRole('technician');
    $this->teacher = userWithRole('teacher');

    $this->writes = [
        ['/api/admin/reports/exports', ['report_type' => 'ticket_overview', 'format' => 'xlsx']],
        ['/api/admin/reports/print', ['report_type' => 'ticket_overview']],
        ['/api/admin/reports/emails/check-recipients', ['report_type' => 'ticket_overview', 'format' => 'pdf', 'recipients' => ['it@sccit.local']]],
        ['/api/admin/reports/emails', ['report_type' => 'ticket_overview', 'format' => 'pdf', 'recipients' => ['it@sccit.local'], 'confirmed_external' => []]],
    ];
});

it('serves every document route to an administrator', function (): void {
    foreach ($this->writes as [$uri, $body]) {
        $this->actingAs($this->admin)->postJson($uri, $body)->assertSuccessful();
    }
    $this->actingAs($this->admin)->getJson('/api/admin/reports/exports')->assertOk();
});

it('refuses technicians, teachers and guests on every document route', function (): void {
    // Guests first: actingAs() would otherwise carry over to the next request.
    foreach ($this->writes as [$uri, $body]) {
        $this->postJson($uri, $body)->assertUnauthorized();
    }

    foreach ($this->writes as [$uri, $body]) {
        $this->actingAs($this->technician)->postJson($uri, $body)->assertForbidden();
        $this->actingAs($this->teacher)->postJson($uri, $body)->assertForbidden();
    }
    $this->actingAs($this->technician)->getJson('/api/admin/reports/exports')->assertForbidden();
    expect(ReportExport::query()->count())->toBe(0);
});

it('stays closed to a technician granted every report permission by hand', function (): void {
    foreach (['reports.view', 'reports.export', 'tickets.view', 'tickets.export'] as $permission) {
        givePermission($this->technician, $permission);
    }

    foreach ($this->writes as [$uri, $body]) {
        $this->actingAs($this->technician)->postJson($uri, $body)->assertForbidden();
    }
});

it('lets a per-user deny of reports.export withdraw documents but not the dashboard', function (): void {
    givePermission($this->admin, 'reports.export', PermissionGrantType::Deny);

    foreach ($this->writes as [$uri, $body]) {
        $this->actingAs($this->admin)->postJson($uri, $body)->assertForbidden();
    }
    $this->actingAs($this->admin)->getJson('/api/admin/reports/ticket-overview')->assertOk();
});

it('answers 403 before validation, so a refused caller learns nothing from messages', function (): void {
    $this->actingAs($this->teacher)
        ->postJson('/api/admin/reports/exports', ['report_type' => 'nope', 'format' => 'exe'])
        ->assertForbidden();
});

it('keeps one administrator\'s documents unreachable by another', function (): void {
    $other = userWithRole('administrator');
    $export = ReportExport::factory()->for($other)->completed()->create();
    Storage::disk('local')->put((string) $export->path, 'x');

    $this->actingAs($this->admin)->getJson("/api/admin/reports/exports/{$export->uuid}")->assertNotFound();
    $this->actingAs($this->admin)->get("/api/admin/reports/exports/{$export->uuid}/download")->assertNotFound();
    $this->actingAs($this->admin)->getJson('/api/admin/reports/exports')->assertOk()->assertJsonCount(0, 'data');

    $invented = '00000000-0000-4000-8000-000000000000';
    $this->actingAs($this->admin)->getJson("/api/admin/reports/exports/{$invented}")->assertNotFound();
});

it('leaves the ticket records out without tickets.export, and says so', function (): void {
    givePermission($this->admin, 'tickets.export', PermissionGrantType::Deny);

    $document = $this->actingAs($this->admin)
        ->postJson('/api/admin/reports/print', ['report_type' => 'ticket_overview', 'include_records' => true])
        ->assertOk()
        ->json('data');

    expect($document['records_included'])->toBeFalse()
        ->and(collect($document['sections'])->pluck('key')->all())->not->toContain('records');
});

it('needs locations.view to narrow a document by location', function (): void {
    givePermission($this->admin, 'locations.view', PermissionGrantType::Deny);
    $room = ReportFixtures::room(ReportFixtures::floor());

    $this->actingAs($this->admin)
        ->postJson('/api/admin/reports/print', ['report_type' => 'ticket_overview', 'room' => $room->uuid])
        ->assertForbidden();
});
