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

it('includes the middle name in the full name when there is one', function () {
    $user = userWithRole('teacher', ['first_name' => 'Maria', 'middle_name' => 'Clara', 'last_name' => 'Santos']);

    $this->actingAs($user)->getJson('/api/user')
        ->assertOk()
        ->assertJsonPath('data.name', 'Maria Clara Santos');

    expect(userWithRole('teacher', ['first_name' => 'Jo', 'middle_name' => null, 'last_name' => 'Reyes'])->fullName())
        ->toBe('Jo Reyes')
        ->and(userWithRole('teacher', ['first_name' => 'Jo', 'middle_name' => '  ', 'last_name' => 'Reyes'])->fullName())
        ->toBe('Jo Reyes');
});

it('lets a user add and then clear their middle name', function () {
    $user = userWithRole('teacher', ['first_name' => 'Maria', 'middle_name' => null, 'last_name' => 'Santos']);

    $this->actingAs($user)->putJson('/api/profile', [
        'first_name' => 'Maria', 'middle_name' => 'Clara', 'last_name' => 'Santos',
    ])->assertOk()->assertJsonPath('data.name', 'Maria Clara Santos');

    // A blank middle name is sent as null, and null clears it.
    $this->actingAs($user)->putJson('/api/profile', [
        'first_name' => 'Maria', 'middle_name' => null, 'last_name' => 'Santos',
    ])->assertOk()->assertJsonPath('data.name', 'Maria Santos');

    expect($user->fresh()->middle_name)->toBeNull();
});
