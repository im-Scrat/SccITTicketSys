<?php

declare(strict_types=1);

use App\Domains\Identity\Services\PermissionResolver;
use App\Enums\PermissionGrantType;
use App\Models\AssetImport;
use App\Models\Permission;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\AssetImportFixtures;

/*
 * Phase 2.10 R6 — who may import assets (SDD DD-96).
 *
 * Administrator role + assets.view + assets.create, and nothing else: not a
 * technician or teacher even with every permission granted by hand (the
 * Gate::before trap), not an administrator whose assets.create is denied.
 * Refusal comes before existence — a real import's uuid and an invented one
 * answer the same 403 — and every route under the prefix carries the group
 * gate.
 */

beforeEach(function (): void {
    seedRbac();
    Storage::fake('local');
    $this->admin = userWithRole('administrator');
    $this->import = AssetImportFixtures::upload($this->admin, AssetImportFixtures::csv([['Unit code', 'Name'], ['PC-1', 'One']]));

    $this->routes = function (string $uuid): array {
        return [
            ['get', '/api/admin/asset-imports'],
            ['get', '/api/admin/asset-imports/template'],
            ['get', '/api/admin/asset-imports/room-options'],
            ['post', '/api/admin/asset-imports'],
            ['get', "/api/admin/asset-imports/{$uuid}"],
            ['get', "/api/admin/asset-imports/{$uuid}/values?column=0&target=status"],
            ['get', "/api/admin/asset-imports/{$uuid}/rows"],
            ['get', "/api/admin/asset-imports/{$uuid}/locations"],
            ['get', "/api/admin/asset-imports/{$uuid}/catalog"],
            ['get', "/api/admin/asset-imports/{$uuid}/file"],
            ['put', "/api/admin/asset-imports/{$uuid}/source"],
            ['put', "/api/admin/asset-imports/{$uuid}/mapping"],
            ['patch', "/api/admin/asset-imports/{$uuid}/rows/2"],
            ['post', "/api/admin/asset-imports/{$uuid}/rows/bulk"],
            ['put', "/api/admin/asset-imports/{$uuid}/locations/".Str::uuid()],
            ['put', "/api/admin/asset-imports/{$uuid}/catalog/".Str::uuid()],
            ['post', "/api/admin/asset-imports/{$uuid}/cancel"],
            ['post', "/api/admin/asset-imports/{$uuid}/confirm"],
            ['post', "/api/admin/asset-imports/{$uuid}/retry"],
        ];
    };
});

it('refuses technicians and teachers on every route, even with the asset permissions granted', function (string $role): void {
    $user = userWithRole($role);
    foreach (['assets.view', 'assets.create', 'assets.update', 'assets.import'] as $permission) {
        if (Permission::query()->where('name', $permission)->exists()) {
            givePermission($user, $permission);
        }
    }
    app(PermissionResolver::class)->forget($user);

    foreach (($this->routes)($this->import->uuid) as [$method, $uri]) {
        $this->actingAs($user)->json($method, $uri)->assertForbidden();
    }
})->with(['technician', 'teacher']);

it('answers a real and an invented import the same way for a non-administrator', function (): void {
    $technician = userWithRole('technician');

    foreach ([$this->import->uuid, (string) Str::uuid()] as $uuid) {
        $this->actingAs($technician)->getJson("/api/admin/asset-imports/{$uuid}")->assertForbidden();
        $this->actingAs($technician)->getJson("/api/admin/asset-imports/{$uuid}/rows")->assertForbidden();
    }
});

it('refuses an administrator whose assets.create is denied', function (): void {
    $other = userWithRole('administrator');
    givePermission($other, 'assets.create', PermissionGrantType::Deny);
    app(PermissionResolver::class)->forget($other);

    $this->actingAs($other)->getJson('/api/admin/asset-imports')->assertForbidden();
    $this->actingAs($other)->getJson("/api/admin/asset-imports/{$this->import->uuid}")->assertForbidden();
});

it('lets any authorised administrator continue another administrator\'s import', function (): void {
    $other = userWithRole('administrator');

    $this->actingAs($other)->getJson("/api/admin/asset-imports/{$this->import->uuid}")->assertOk();
    $this->actingAs($other)->postJson("/api/admin/asset-imports/{$this->import->uuid}/cancel")->assertOk();
    expect($this->import->refresh()->status->value)->toBe('cancelled');
});

it('answers 404 for an invented import an administrator asks for', function (): void {
    $this->actingAs($this->admin)->getJson('/api/admin/asset-imports/'.Str::uuid())->assertNotFound();
    $this->actingAs($this->admin)->getJson('/api/admin/asset-imports/not-a-uuid')->assertNotFound();
});

it('scopes a decision uuid to its own import', function (): void {
    $file = AssetImportFixtures::csv([['Unit code', 'Name', 'Room'], ['PC-7', 'Seven', 'Somewhere']]);
    $other = AssetImportFixtures::upload($this->admin, $file);
    AssetImportFixtures::map($this->admin, $other)->assertOk();
    $location = $other->locations()->firstOrFail();

    AssetImportFixtures::map($this->admin, $this->import)->assertOk();
    $this->actingAs($this->admin)->putJson("/api/admin/asset-imports/{$this->import->uuid}/locations/{$location->uuid}", ['resolution' => 'unassigned'])
        ->assertNotFound();
});

it('requires a session for every route', function (): void {
    // The upload in beforeEach signed the administrator in.
    app('auth')->forgetGuards();

    foreach (($this->routes)($this->import->uuid) as [$method, $uri]) {
        $this->json($method, $uri)->assertUnauthorized();
    }
});

it('closes every route under /api/admin/asset-imports with the import policy gate', function (): void {
    $gate = 'can:viewAny,'.AssetImport::class;
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => str_starts_with($route->uri(), 'api/admin/asset-imports'));

    expect($routes->count())->toBeGreaterThanOrEqual(19);

    $routes->each(function (RoutingRoute $route) use ($gate): void {
        expect($route->gatherMiddleware())->toContain($gate);
        if (! in_array('GET', $route->methods(), true)) {
            expect(collect($route->gatherMiddleware())->contains(fn (string $m): bool => str_starts_with($m, 'can:manage')))->toBeTrue();
        }
        // A permission-string gate here would be opened by a per-user grant.
        foreach ($route->gatherMiddleware() as $middleware) {
            expect($middleware)->not->toStartWith('can:assets.');
        }
    });
});
