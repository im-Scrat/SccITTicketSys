<?php

declare(strict_types=1);

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

beforeEach(fn () => seedRbac());

it('sends a reset link and responds generically', function () {
    Notification::fake();
    $user = userWithRole('teacher', ['email' => 'reset@example.com']);

    $this->postJson('/api/forgot-password', ['email' => 'reset@example.com'])->assertOk();

    Notification::assertSentTo($user, ResetPassword::class);
});

it('does not reveal whether the email exists on forgot-password', function () {
    $this->postJson('/api/forgot-password', ['email' => 'unknown@example.com'])
        ->assertOk()
        ->assertJsonPath('message', fn ($m) => is_string($m));
});

it('resets the password with a valid token', function () {
    $user = userWithRole('teacher', ['email' => 'reset@example.com']);
    $token = Password::createToken($user);

    $this->postJson('/api/reset-password', [
        'token' => $token,
        'email' => 'reset@example.com',
        'password' => 'Br4nd-New-Pass!',
        'password_confirmation' => 'Br4nd-New-Pass!',
    ])->assertOk();

    expect(Hash::check('Br4nd-New-Pass!', $user->fresh()->password))->toBeTrue();
});

it('rejects an invalid reset token', function () {
    userWithRole('teacher', ['email' => 'reset@example.com']);

    $this->postJson('/api/reset-password', [
        'token' => 'invalid-token',
        'email' => 'reset@example.com',
        'password' => 'Br4nd-New-Pass!',
        'password_confirmation' => 'Br4nd-New-Pass!',
    ])->assertStatus(422);
});

it('changes the password when the current password is correct', function () {
    $user = userWithRole('teacher', ['password' => 'Old-Passw0rd!']);

    $this->actingAs($user)->putJson('/api/password', [
        'current_password' => 'Old-Passw0rd!',
        'password' => 'New-Passw0rd!',
        'password_confirmation' => 'New-Passw0rd!',
    ])->assertOk();

    expect(Hash::check('New-Passw0rd!', $user->fresh()->password))->toBeTrue();
});

it('rejects a password change with the wrong current password', function () {
    $user = userWithRole('teacher', ['password' => 'Old-Passw0rd!']);

    $this->actingAs($user)->putJson('/api/password', [
        'current_password' => 'not-the-current',
        'password' => 'New-Passw0rd!',
        'password_confirmation' => 'New-Passw0rd!',
    ])->assertStatus(422)->assertJsonValidationErrorFor('current_password');
});
