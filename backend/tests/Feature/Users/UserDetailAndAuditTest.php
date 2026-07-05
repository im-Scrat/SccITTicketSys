<?php

declare(strict_types=1);

use App\Models\Permission;

beforeEach(fn () => seedRbac());

it('returns the full user profile with derived signals', function () {
    $admin = userWithRole('administrator');
    $user = userWithRole('teacher', ['registration_source' => 'admin']);
    $user->directPermissions()->attach(Permission::where('name', 'assets.view')->value('id'), ['grant_type' => 'grant']);

    $this->actingAs($admin)->getJson("/api/admin/users/{$user->uuid}")
        ->assertOk()
        ->assertJsonPath('data.id', $user->uuid)
        ->assertJsonPath('data.additional_permissions.grants', ['assets.view'])
        ->assertJsonStructure(['data' => [
            'effective_permissions', 'additional_permissions' => ['grants', 'denies'],
            'lockout' => ['locked', 'available_in_seconds'], 'session' => ['active'],
            'registration_source', 'last_activity_at', 'force_password_reset',
        ]]);
});

it('can view an archived user detail', function () {
    $admin = userWithRole('administrator');
    $user = userWithRole('teacher');
    $user->delete();

    $this->actingAs($admin)->getJson("/api/admin/users/{$user->uuid}")
        ->assertOk()
        ->assertJsonPath('data.archived_at', fn ($v) => $v !== null);
});

it('returns a chronological audit timeline for the user', function () {
    $admin = userWithRole('administrator');
    $user = userWithRole('teacher');

    // Generate a couple of audited events about the user.
    $this->actingAs($admin)->postJson("/api/admin/users/{$user->uuid}/suspend", ['reason' => 'x'])->assertOk();
    $this->actingAs($admin)->postJson("/api/admin/users/{$user->uuid}/reactivate")->assertOk();

    $this->actingAs($admin)->getJson("/api/admin/users/{$user->uuid}/audit")
        ->assertOk()
        ->assertJsonStructure(['data' => [['action', 'label', 'created_at']]])
        ->assertJsonPath('data.0.action', 'user_reactivated'); // newest first
});
