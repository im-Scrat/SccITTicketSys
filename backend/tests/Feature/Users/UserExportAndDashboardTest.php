<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;

beforeEach(fn () => seedRbac());

it('exports the directory as xlsx and csv honouring filters', function () {
    $admin = userWithRole('administrator');
    userWithRole('teacher', ['status' => 'suspended']);

    $this->actingAs($admin)->get('/api/admin/users/export?format=xlsx&status=suspended')
        ->assertOk()->assertDownload();

    $this->actingAs($admin)->get('/api/admin/users/export?format=csv')
        ->assertOk()->assertDownload();
});

it('audits an export', function () {
    $admin = userWithRole('administrator');

    $this->actingAs($admin)->get('/api/admin/users/export?format=csv')->assertOk();

    $this->assertDatabaseHas('activity_logs', ['action' => 'users_exported', 'user_id' => $admin->id]);
});

it('forbids export without the permission', function () {
    $teacher = userWithRole('teacher');

    $this->actingAs($teacher)->get('/api/admin/users/export')->assertForbidden();
});

it('returns dashboard metrics with status and role counts', function () {
    $admin = userWithRole('administrator');
    $teacherRole = Role::query()->where('slug', 'teacher')->value('id');
    User::factory(3)->create(['role_id' => $teacherRole]);
    User::factory(2)->suspended()->create(['role_id' => $teacherRole]);

    $this->actingAs($admin)->getJson('/api/admin/users/dashboard')
        ->assertOk()
        ->assertJsonPath('data.summary.suspended', 2)
        ->assertJsonPath('data.summary.administrators', 1)
        ->assertJsonStructure(['data' => [
            'summary' => ['total', 'active', 'pending', 'suspended', 'rejected', 'inactive', 'administrators', 'technicians', 'teachers'],
            'recent_registrations', 'recent_logins', 'recent_actions',
        ]]);
});

it('forbids the dashboard to non-managers', function () {
    $teacher = userWithRole('teacher');

    $this->actingAs($teacher)->getJson('/api/admin/users/dashboard')->assertForbidden();
});
