<?php

declare(strict_types=1);

use App\Domains\Identity\Services\PermissionResolver;
use App\Models\Permission;

beforeEach(fn () => seedRbac());

it('changes a role and the effective permissions follow immediately', function () {
    $admin = userWithRole('administrator');
    $user = userWithRole('teacher');
    expect($user->hasPermissionTo('maintenance.create'))->toBeFalse();

    $this->actingAs($admin)->putJson("/api/admin/users/{$user->uuid}/role", ['role' => 'technician'])->assertOk();

    expect($user->fresh()->hasPermissionTo('maintenance.create'))->toBeTrue();
    $this->assertDatabaseHas('activity_logs', ['action' => 'role_changed', 'subject_id' => $user->id]);
});

it('forbids changing your own role', function () {
    $admin = userWithRole('administrator');

    $this->actingAs($admin)->putJson("/api/admin/users/{$admin->uuid}/role", ['role' => 'teacher'])->assertForbidden();
});

it('forbids demoting the last active administrator', function () {
    $actor = userWithRole('technician');
    $actor->directPermissions()->attach(Permission::where('name', 'users.update')->value('id'), ['grant_type' => 'grant']);
    $soleAdmin = userWithRole('administrator');

    $this->actingAs($actor)->putJson("/api/admin/users/{$soleAdmin->uuid}/role", ['role' => 'teacher'])->assertForbidden();
    expect($soleAdmin->fresh()->isAdministrator())->toBeTrue();
});

it('applies grant and deny overrides and reflects them in the effective set', function () {
    $admin = userWithRole('administrator');
    $user = userWithRole('teacher');

    $this->actingAs($admin)->putJson("/api/admin/users/{$user->uuid}/permissions", [
        'grants' => ['assets.view'],
        'denies' => ['tickets.view'], // teacher has tickets.view by role
    ])->assertOk();

    $effective = app(PermissionResolver::class)->resolve($user->fresh());
    expect($effective)->toContain('assets.view')
        ->and($effective)->not->toContain('tickets.view');
});

it('rejects a permission that is both granted and denied', function () {
    $admin = userWithRole('administrator');
    $user = userWithRole('teacher');

    $this->actingAs($admin)->putJson("/api/admin/users/{$user->uuid}/permissions", [
        'grants' => ['assets.view'], 'denies' => ['assets.view'],
    ])->assertStatus(422)->assertJsonValidationErrors(['grants']);
});

it('forbids managing your own permissions', function () {
    $admin = userWithRole('administrator');

    $this->actingAs($admin)->putJson("/api/admin/users/{$admin->uuid}/permissions", [
        'grants' => [], 'denies' => ['users.update'],
    ])->assertForbidden();
});

it('returns the permission matrix payload', function () {
    $admin = userWithRole('administrator');
    $user = userWithRole('teacher');

    $this->actingAs($admin)->getJson("/api/admin/users/{$user->uuid}/permissions")
        ->assertOk()
        ->assertJsonStructure(['data' => ['effective', 'role_permissions', 'grants', 'denies', 'all_permissions']]);
});
