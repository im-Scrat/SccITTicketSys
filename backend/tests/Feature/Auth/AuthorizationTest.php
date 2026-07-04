<?php

declare(strict_types=1);

use App\Domains\Identity\Services\PermissionResolver;
use App\Models\Permission;

beforeEach(fn () => seedRbac());

it('grants a role its seeded permissions', function () {
    $tech = userWithRole('technician');

    expect($tech->hasPermissionTo('maintenance.create'))->toBeTrue();
    expect($tech->hasPermissionTo('users.create'))->toBeFalse();
});

it('gives the administrator every permission', function () {
    $admin = userWithRole('administrator');

    expect($admin->hasPermissionTo('users.update'))->toBeTrue();
    expect($admin->hasPermissionTo('system.settings.manage'))->toBeTrue();
});

it('applies a per-user grant override', function () {
    $teacher = userWithRole('teacher');
    expect($teacher->hasPermissionTo('assets.view'))->toBeFalse();

    $teacher->directPermissions()->attach(
        Permission::where('name', 'assets.view')->value('id'),
        ['grant_type' => 'grant'],
    );
    app(PermissionResolver::class)->forget($teacher);

    expect($teacher->hasPermissionTo('assets.view'))->toBeTrue();
});

it('lets a deny override win over a role permission', function () {
    $tech = userWithRole('technician');
    expect($tech->hasPermissionTo('maintenance.create'))->toBeTrue();

    $tech->directPermissions()->attach(
        Permission::where('name', 'maintenance.create')->value('id'),
        ['grant_type' => 'deny'],
    );
    app(PermissionResolver::class)->forget($tech);

    expect($tech->hasPermissionTo('maintenance.create'))->toBeFalse();
});

it('denies unknown permissions by default', function () {
    $admin = userWithRole('administrator');

    expect($admin->hasPermissionTo('nonexistent.permission'))->toBeFalse();
});

it('exposes effective permissions on the user resource', function () {
    $tech = userWithRole('technician');

    $this->actingAs($tech)->getJson('/api/user')
        ->assertOk()
        ->assertJsonPath('data.permissions', fn ($permissions) => in_array('maintenance.create', $permissions, true));
});
