<?php

declare(strict_types=1);

use App\Models\Permission;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    seedRbac();
    // The payload is cached per user for 30 s (FR-DSH-005); each test starts cold.
    Cache::flush();
});

/**
 * @return list<string>
 */
function widgetKeys(array $payload): array
{
    return array_map(static fn (array $widget): string => $widget['key'], $payload);
}

it('requires an authenticated account', function () {
    $this->getJson('/api/dashboard/widgets')->assertUnauthorized();
});

it('gives an administrator the cross-organization operations layout', function () {
    $response = $this->actingAs(userWithRole('administrator'))
        ->getJson('/api/dashboard/widgets')
        ->assertOk()
        ->assertJsonPath('data.role', 'administrator');

    $keys = widgetKeys($response->json('data.widgets'));

    expect($keys)->toContain('service-desk')
        ->and($keys)->toContain('tickets-by-status')
        ->and($keys)->toContain('open-by-priority')
        ->and($keys)->toContain('technician-workload')
        ->and($keys)->toContain('maintenance')
        ->and($keys)->toContain('inventory')
        ->and($keys)->toContain('estate')
        ->and($keys)->toContain('people')
        ->and($keys)->toContain('recent-activity');
});

it('gives a technician the work-queue layout and no administrative figures', function () {
    $response = $this->actingAs(userWithRole('technician'))
        ->getJson('/api/dashboard/widgets')
        ->assertOk()
        ->assertJsonPath('data.role', 'technician');

    $keys = widgetKeys($response->json('data.widgets'));

    expect($keys)->toContain('my-work')
        ->and($keys)->toContain('my-assignments')
        ->and($keys)->toContain('unassigned-queue')
        ->and($keys)->toContain('my-maintenance')
        ->and($keys)->toContain('maintenance-schedule')
        ->and($keys)->toContain('low-stock')
        // Administrator-only surfaces must never appear.
        ->and($keys)->not->toContain('people')
        ->and($keys)->not->toContain('recent-activity')
        ->and($keys)->not->toContain('technician-workload')
        ->and($keys)->not->toContain('estate');
});

it('gives a teacher only their own requests and the report action', function () {
    $response = $this->actingAs(userWithRole('teacher'))
        ->getJson('/api/dashboard/widgets')
        ->assertOk()
        ->assertJsonPath('data.role', 'teacher');

    $keys = widgetKeys($response->json('data.widgets'));

    expect($keys)->toContain('my-requests')
        ->and($keys)->toContain('my-recent-requests')
        ->and($keys)->toContain('quick-actions')
        ->and($keys)->not->toContain('service-desk')
        ->and($keys)->not->toContain('unassigned-queue')
        ->and($keys)->not->toContain('low-stock')
        ->and($keys)->not->toContain('people');
});

it('drops a widget when the permission behind it is revoked', function () {
    $admin = userWithRole('administrator');

    $permission = Permission::query()->where('name', 'users.view')->firstOrFail();
    $admin->directPermissions()->attach($permission->id, ['grant_type' => 'deny']);

    $response = $this->actingAs($admin->fresh())->getJson('/api/dashboard/widgets')->assertOk();

    expect(widgetKeys($response->json('data.widgets')))->not->toContain('people');
});

it('drops the inventory widgets when both inventory and asset reads are revoked', function () {
    $admin = userWithRole('administrator');

    foreach (['inventory.view', 'assets.view'] as $name) {
        $permission = Permission::query()->where('name', $name)->firstOrFail();
        $admin->directPermissions()->attach($permission->id, ['grant_type' => 'deny']);
    }

    $response = $this->actingAs($admin->fresh())->getJson('/api/dashboard/widgets')->assertOk();
    $keys = widgetKeys($response->json('data.widgets'));

    expect($keys)->not->toContain('inventory')
        ->and($keys)->not->toContain('low-stock')
        // The rest of the layout is unaffected.
        ->and($keys)->toContain('service-desk');
});

it('every widget declares a key, type and title', function () {
    foreach (['administrator', 'technician', 'teacher'] as $roleSlug) {
        Cache::flush();

        $widgets = $this->actingAs(userWithRole($roleSlug))
            ->getJson('/api/dashboard/widgets')
            ->assertOk()
            ->json('data.widgets');

        foreach ($widgets as $widget) {
            expect($widget)->toHaveKeys(['key', 'type', 'title'])
                ->and($widget['type'])->toBeIn(['kpi', 'distribution', 'list', 'actions', 'announcements']);
        }
    }
});
