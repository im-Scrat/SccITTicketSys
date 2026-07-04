<?php

declare(strict_types=1);

use App\Enums\LoginStatus;
use App\Models\LoginHistory;

beforeEach(fn () => seedRbac());

it('authenticates an active user with valid credentials', function () {
    $user = userWithRole('teacher', [
        'email' => 'teacher@example.com',
        'password' => 'Str0ng-Passw0rd!',
    ]);

    $response = $this->postJson('/api/login', [
        'email' => 'teacher@example.com',
        'password' => 'Str0ng-Passw0rd!',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.email', 'teacher@example.com')
        ->assertJsonPath('data.role.slug', 'teacher')
        ->assertJsonStructure(['data' => ['id', 'permissions', 'status']]);

    $this->assertAuthenticated();

    expect(LoginHistory::where('user_id', $user->id)->where('login_status', LoginStatus::Success->value)->exists())->toBeTrue();
    expect($user->fresh()->last_login_at)->not->toBeNull();
});

it('rejects invalid credentials with a non-disclosing message', function () {
    userWithRole('teacher', ['email' => 'teacher@example.com', 'password' => 'Str0ng-Passw0rd!']);

    $this->postJson('/api/login', [
        'email' => 'teacher@example.com',
        'password' => 'wrong-password',
    ])->assertStatus(422)
        ->assertJsonValidationErrorFor('email');

    $this->assertGuest();
    expect(LoginHistory::where('login_status', LoginStatus::Failed->value)->exists())->toBeTrue();
});

it('does not reveal whether an unknown email exists', function () {
    $this->postJson('/api/login', [
        'email' => 'nobody@example.com',
        'password' => 'whatever-123',
    ])->assertStatus(422)->assertJsonValidationErrorFor('email');

    $this->assertGuest();
});

it('blocks a pending account from signing in with a status code', function () {
    userWithRole('teacher', [
        'email' => 'pending@example.com',
        'password' => 'Str0ng-Passw0rd!',
        'status' => 'pending',
    ]);

    $this->postJson('/api/login', [
        'email' => 'pending@example.com',
        'password' => 'Str0ng-Passw0rd!',
    ])->assertStatus(403)->assertJsonPath('code', 'pending');

    $this->assertGuest();
});

it('blocks rejected, suspended, and inactive accounts', function (string $status) {
    userWithRole('teacher', [
        'email' => "{$status}@example.com",
        'password' => 'Str0ng-Passw0rd!',
        'status' => $status,
    ]);

    $this->postJson('/api/login', [
        'email' => "{$status}@example.com",
        'password' => 'Str0ng-Passw0rd!',
    ])->assertStatus(403)->assertJsonPath('code', $status);
})->with(['rejected', 'suspended', 'inactive']);

it('logs out and stamps the login history', function () {
    $user = userWithRole('teacher');
    LoginHistory::create([
        'user_id' => $user->id,
        'login_at' => now()->subMinutes(5),
        'login_status' => LoginStatus::Success->value,
    ]);

    // Session invalidation + CSRF-token regeneration are handled by AuthService
    // (standard Laravel) and verified in the live end-to-end pass.
    $this->actingAs($user)->postJson('/api/logout')->assertOk();

    expect(LoginHistory::where('user_id', $user->id)->whereNotNull('logout_at')->exists())->toBeTrue();
});

it('returns the authenticated principal from /api/user', function () {
    $user = userWithRole('technician');

    $this->actingAs($user)->getJson('/api/user')
        ->assertOk()
        ->assertJsonPath('data.role.slug', 'technician')
        ->assertJsonPath('data.id', $user->uuid);
});

it('rejects an unauthenticated request to /api/user', function () {
    $this->getJson('/api/user')->assertUnauthorized();
});
