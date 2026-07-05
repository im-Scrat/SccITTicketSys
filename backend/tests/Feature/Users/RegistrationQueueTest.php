<?php

declare(strict_types=1);

beforeEach(fn () => seedRbac());

it('searches and paginates the pending registration queue', function () {
    $admin = userWithRole('administrator');
    userWithRole('teacher', ['status' => 'pending', 'first_name' => 'Marisol', 'email' => 'marisol@example.com']);
    userWithRole('technician', ['status' => 'pending']);
    userWithRole('teacher', ['status' => 'active']); // not pending — excluded

    $this->actingAs($admin)->getJson('/api/admin/registrations')
        ->assertOk()
        ->assertJsonPath('meta.total', 2);

    $this->actingAs($admin)->getJson('/api/admin/registrations?search=marisol')
        ->assertOk()
        ->assertJsonPath('meta.total', 1);
});

it('edits a pending registration before a decision', function () {
    $admin = userWithRole('administrator');
    $pending = userWithRole('teacher', ['status' => 'pending', 'first_name' => 'Wrong']);

    $this->actingAs($admin)->putJson("/api/admin/registrations/{$pending->uuid}", [
        'first_name' => 'Right', 'last_name' => $pending->last_name, 'email' => $pending->email,
        'role' => 'technician',
    ])->assertOk()->assertJsonPath('data.first_name', 'Right');

    $fresh = $pending->fresh();
    expect($fresh->first_name)->toBe('Right')
        ->and($fresh->role->slug)->toBe('technician')
        ->and($fresh->status->value)->toBe('pending');

    $this->assertDatabaseHas('activity_logs', ['action' => 'registration_updated', 'subject_id' => $pending->id]);
});

it('refuses to edit a non-pending account through the registration endpoint', function () {
    $admin = userWithRole('administrator');
    $active = userWithRole('teacher', ['status' => 'active']);

    $this->actingAs($admin)->putJson("/api/admin/registrations/{$active->uuid}", [
        'first_name' => 'X', 'last_name' => 'Y', 'email' => $active->email,
    ])->assertForbidden();
});
