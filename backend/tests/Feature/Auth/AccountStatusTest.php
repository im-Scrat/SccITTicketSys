<?php

declare(strict_types=1);

beforeEach(fn () => seedRbac());

it('lets an active account through the status middleware', function () {
    $user = userWithRole('teacher', ['status' => 'active']);

    $this->actingAs($user)->getJson('/api/user')->assertOk();
});

it('blocks non-active accounts with a status-specific 403', function (string $status) {
    $user = userWithRole('teacher', ['status' => $status]);

    $this->actingAs($user)->getJson('/api/user')
        ->assertStatus(403)
        ->assertJsonPath('code', $status);
})->with(['pending', 'rejected', 'suspended', 'inactive']);

it('still allows logout for a suspended session', function () {
    $user = userWithRole('teacher', ['status' => 'suspended']);

    $this->actingAs($user)->postJson('/api/logout')->assertOk();
});
