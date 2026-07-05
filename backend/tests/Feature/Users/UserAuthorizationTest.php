<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\User;

beforeEach(fn () => seedRbac());

/** Grant a single permission override to a fresh non-privileged user. */
function viewerWith(string ...$permissions): User
{
    $user = userWithRole('technician');
    foreach ($permissions as $name) {
        $user->directPermissions()->attach(Permission::where('name', $name)->value('id'), ['grant_type' => 'grant']);
    }

    return $user->fresh();
}

it('requires authentication on every admin endpoint', function () {
    $target = userWithRole('teacher');

    $this->getJson('/api/admin/users')->assertUnauthorized();
    $this->getJson('/api/admin/users/dashboard')->assertUnauthorized();
    $this->postJson('/api/admin/users')->assertUnauthorized();
    $this->deleteJson("/api/admin/users/{$target->uuid}")->assertUnauthorized();
});

it('forbids a user with no user-management permissions', function () {
    $teacher = userWithRole('teacher');
    $target = userWithRole('teacher');

    $this->actingAs($teacher)->getJson('/api/admin/users')->assertForbidden();
    $this->actingAs($teacher)->postJson('/api/admin/users', [])->assertForbidden();
    $this->actingAs($teacher)->deleteJson("/api/admin/users/{$target->uuid}")->assertForbidden();
});

it('enforces least privilege: users.view can read but not mutate', function () {
    $viewer = viewerWith('users.view');
    $target = userWithRole('teacher');

    $this->actingAs($viewer)->getJson('/api/admin/users')->assertOk();
    $this->actingAs($viewer)->getJson("/api/admin/users/{$target->uuid}")->assertOk();

    // No create / update / delete without the specific permission.
    $this->actingAs($viewer)->postJson('/api/admin/users', [
        'role' => 'teacher', 'first_name' => 'A', 'last_name' => 'B',
        'email' => 'x@example.com', 'password' => 'Str0ng-P@ssw0rd', 'password_confirmation' => 'Str0ng-P@ssw0rd',
    ])->assertForbidden();
    $this->actingAs($viewer)->putJson("/api/admin/users/{$target->uuid}", [
        'first_name' => 'A', 'last_name' => 'B', 'email' => $target->email,
    ])->assertForbidden();
    $this->actingAs($viewer)->deleteJson("/api/admin/users/{$target->uuid}")->assertForbidden();
});

it('scopes create to users.create and delete to users.delete', function () {
    $creator = viewerWith('users.view', 'users.create');
    $this->actingAs($creator)->postJson('/api/admin/users', [
        'role' => 'teacher', 'first_name' => 'A', 'last_name' => 'B',
        'email' => 'created@example.com', 'password' => 'Str0ng-P@ssw0rd', 'password_confirmation' => 'Str0ng-P@ssw0rd',
    ])->assertCreated();

    $target = userWithRole('teacher');
    $this->actingAs($creator)->deleteJson("/api/admin/users/{$target->uuid}")->assertForbidden();

    $remover = viewerWith('users.view', 'users.delete');
    $this->actingAs($remover)->deleteJson("/api/admin/users/{$target->uuid}")->assertOk();
});
