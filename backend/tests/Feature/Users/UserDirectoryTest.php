<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;

beforeEach(fn () => seedRbac());

it('paginates the directory server-side', function () {
    $admin = userWithRole('administrator');
    $teacherRole = Role::query()->where('slug', 'teacher')->value('id');
    User::factory(30)->create(['role_id' => $teacherRole]);

    $this->actingAs($admin)->getJson('/api/admin/users?per_page=10')
        ->assertOk()
        ->assertJsonCount(10, 'data')
        ->assertJsonPath('meta.per_page', 10)
        ->assertJsonPath('meta.total', 31); // 30 + the admin
});

it('searches by name, email and employee number', function () {
    $admin = userWithRole('administrator');
    $target = userWithRole('teacher', [
        'first_name' => 'Zephyrine', 'last_name' => 'Quill',
        'email' => 'zephyrine.quill@example.com', 'employee_number' => 'EMP-99999',
    ]);

    foreach (['Zephyrine', 'quill@example', 'EMP-99999'] as $term) {
        $this->actingAs($admin)->getJson('/api/admin/users?search='.urlencode($term))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $target->uuid);
    }
});

it('filters by status and role', function () {
    $admin = userWithRole('administrator');
    userWithRole('teacher', ['status' => 'active']);
    userWithRole('teacher', ['status' => 'suspended']);
    userWithRole('technician', ['status' => 'suspended']);

    $this->actingAs($admin)->getJson('/api/admin/users?status=suspended')
        ->assertOk()
        ->assertJsonPath('meta.total', 2);

    $this->actingAs($admin)->getJson('/api/admin/users?role=technician')
        ->assertOk()
        ->assertJsonPath('meta.total', 1);
});

it('sorts by a whitelisted column and rejects an unknown one', function () {
    $admin = userWithRole('administrator');
    userWithRole('teacher', ['first_name' => 'Aaron', 'last_name' => 'Ant']);
    userWithRole('teacher', ['first_name' => 'Zoe', 'last_name' => 'Zulu']);

    $this->actingAs($admin)->getJson('/api/admin/users?sort=name&direction=asc')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Aaron Ant');

    $this->actingAs($admin)->getJson('/api/admin/users?sort=hax;drop')
        ->assertStatus(422);
});

it('excludes archived users by default and can list only archived', function () {
    $admin = userWithRole('administrator');
    $archived = userWithRole('teacher');
    $archived->delete();

    $this->actingAs($admin)->getJson('/api/admin/users')
        ->assertOk()
        ->assertJsonMissing(['id' => $archived->uuid]);

    $this->actingAs($admin)->getJson('/api/admin/users?trashed=only')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $archived->uuid);
});

it('forbids the directory to users without the permission', function () {
    $teacher = userWithRole('teacher');

    $this->actingAs($teacher)->getJson('/api/admin/users')->assertForbidden();
});

it('requires authentication', function () {
    $this->getJson('/api/admin/users')->assertUnauthorized();
});
