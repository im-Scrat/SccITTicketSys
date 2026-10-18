<?php

declare(strict_types=1);

use App\Domains\Analytics\Reporting\ReportArea;
use App\Domains\KnowledgeBase\Services\AssistantContext;
use App\Enums\PermissionGrantType;
use Database\Seeders\TicketLookupSeeder;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\Support\ReportFixtures;

/*
 * Phase 2.10 — who may reach reports (owner decision D8, SDD DD-89).
 *
 * "Every report endpoint requires the Administrator role plus reports.view."
 * Proved role by role, including the traps ReportsAccess names: the technician
 * baseline *holds* reports.view and must still be refused; a per-user grant to a
 * non-administrator opens nothing; a per-user deny withdraws reports from a
 * named administrator; and the module gates (tickets.view, locations.view) apply
 * inside the report area too.
 */

const REPORT_ENDPOINTS = ['/api/admin/reports/ticket-overview', '/api/admin/reports/options'];

beforeEach(function (): void {
    seedRbac();
    $this->seed(TicketLookupSeeder::class);

    $this->admin = userWithRole('administrator');
    $this->technician = userWithRole('technician');
    $this->teacher = userWithRole('teacher');

    $this->room = ReportFixtures::room(ReportFixtures::floor());
});

/* ------------------------------------------------------------- role gates */

it('serves both report endpoints to an administrator', function (): void {
    foreach (REPORT_ENDPOINTS as $endpoint) {
        $this->actingAs($this->admin)->getJson($endpoint)->assertOk();
    }
});

it('refuses a technician although the technician baseline holds reports.view', function (): void {
    expect($this->technician->hasPermissionTo('reports.view'))->toBeTrue();

    foreach (REPORT_ENDPOINTS as $endpoint) {
        $this->actingAs($this->technician)->getJson($endpoint)->assertForbidden();
    }
});

it('refuses a teacher', function (): void {
    foreach (REPORT_ENDPOINTS as $endpoint) {
        $this->actingAs($this->teacher)->getJson($endpoint)->assertForbidden();
    }
});

it('refuses an unauthenticated caller', function (): void {
    foreach (REPORT_ENDPOINTS as $endpoint) {
        $this->getJson($endpoint)->assertUnauthorized();
    }
});

it('stays closed to a teacher granted every report permission by hand', function (): void {
    givePermission($this->teacher, 'reports.view');
    givePermission($this->teacher, 'reports.export');
    givePermission($this->teacher, 'tickets.view');

    foreach (REPORT_ENDPOINTS as $endpoint) {
        $this->actingAs($this->teacher)->getJson($endpoint)->assertForbidden();
    }
});

it('lets a per-user deny withdraw reports from a named administrator', function (): void {
    givePermission($this->admin, 'reports.view', PermissionGrantType::Deny);

    foreach (REPORT_ENDPOINTS as $endpoint) {
        $this->actingAs($this->admin)->getJson($endpoint)->assertForbidden();
    }
});

/* ------------------------------------------------------------ domain gates */

it('needs tickets.view for the ticket overview and its options', function (): void {
    givePermission($this->admin, 'tickets.view', PermissionGrantType::Deny);

    foreach (REPORT_ENDPOINTS as $endpoint) {
        $this->actingAs($this->admin)->getJson($endpoint)->assertForbidden();
    }
});

it('leaves location blocks out for an administrator without locations.view', function (): void {
    givePermission($this->admin, 'locations.view', PermissionGrantType::Deny);

    $overview = $this->actingAs($this->admin)->getJson('/api/admin/reports/ticket-overview')->assertOk();
    $options = $this->actingAs($this->admin)->getJson('/api/admin/reports/options')->assertOk();

    expect($overview->json('data.locations'))->toBeNull()
        ->and($overview->json('meta.visible.locations'))->toBeFalse()
        ->and($options->json('data.rooms'))->toBe([])
        ->and($options->json('data.buildings'))->toBe([]);
});

it('refuses a location filter to an administrator without locations.view, before validating it', function (): void {
    givePermission($this->admin, 'locations.view', PermissionGrantType::Deny);

    $this->actingAs($this->admin)
        ->getJson('/api/admin/reports/ticket-overview?room='.$this->room->uuid)
        ->assertForbidden();
});

/* ------------------------------------------------ 403 first, no oracle */

it('answers a non-administrator 403 before any validation message', function (): void {
    $this->actingAs($this->technician)
        ->getJson('/api/admin/reports/ticket-overview?period=nonsense&status=nope&room=not-a-uuid')
        ->assertForbidden();
});

it('answers a real and an invented room the same way for a non-administrator', function (): void {
    $real = $this->actingAs($this->technician)->getJson('/api/admin/reports/ticket-overview?room='.$this->room->uuid);
    $fake = $this->actingAs($this->technician)->getJson('/api/admin/reports/ticket-overview?room=00000000-0000-4000-8000-000000000000');

    expect($real->status())->toBe(403)->and($fake->status())->toBe(403);
});

/* ------------------------------------------------------ the AI assistant */

it('never tells a technician the assistant can show them reports', function (): void {
    $context = app(AssistantContext::class);

    expect($context->userBlock($this->technician))->not->toContain('view reports and analytics')
        ->and($context->userBlock($this->admin))->toContain('view reports and analytics');
});

/* ------------------------------------------------------------- route table */

it('closes every route under /api/admin/reports with the report-area policy gate', function (): void {
    $gate = 'can:viewAny,'.ReportArea::class;
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => str_starts_with($route->uri(), 'api/admin/reports'));

    expect($routes)->not->toBeEmpty();

    $routes->each(function (RoutingRoute $route) use ($gate): void {
        expect($route->gatherMiddleware())->toContain($gate);

        // A permission-string gate here would be opened by a per-user grant.
        foreach ($route->gatherMiddleware() as $middleware) {
            expect($middleware)->not->toStartWith('can:reports.');
        }
    });
});
