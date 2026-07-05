<?php

declare(strict_types=1);

beforeEach(fn () => seedRbac());

it('lets a user change their own name', function () {
    $user = userWithRole('teacher', ['first_name' => 'Old', 'last_name' => 'Name']);

    $this->actingAs($user)->putJson('/api/profile', ['first_name' => 'New', 'last_name' => 'Name'])
        ->assertOk()
        ->assertJsonPath('data.first_name', 'New');

    $fresh = $user->fresh();
    expect($fresh->first_name)->toBe('New')
        ->and($fresh->updated_by)->toBe($user->id);
    $this->assertDatabaseHas('activity_logs', ['action' => 'user_updated', 'subject_id' => $user->id]);
});

it('cannot escalate role or status through the profile endpoint', function () {
    $user = userWithRole('teacher');

    $this->actingAs($user)->putJson('/api/profile', [
        'first_name' => 'X', 'last_name' => 'Y', 'role' => 'administrator', 'status' => 'active',
    ])->assertOk();

    $fresh = $user->fresh();
    expect($fresh->role->slug)->toBe('teacher')
        ->and($fresh->isAdministrator())->toBeFalse();
});

it('requires authentication', function () {
    $this->putJson('/api/profile', ['first_name' => 'X', 'last_name' => 'Y'])->assertUnauthorized();
});
