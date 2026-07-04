<?php

declare(strict_types=1);

use App\Domains\Identity\Notifications\RegistrationSubmitted;
use App\Enums\UserStatus;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

beforeEach(fn () => seedRbac());

function validRegistrationPayload(array $overrides = []): array
{
    return [...[
        'first_name' => 'Alex',
        'last_name' => 'Rivera',
        'email' => 'alex@example.com',
        'role' => 'teacher',
        'password' => 'Str0ng-Passw0rd!',
        'password_confirmation' => 'Str0ng-Passw0rd!',
    ], ...$overrides];
}

it('creates a pending registration request for a teacher', function () {
    Notification::fake();

    $this->postJson('/api/register', validRegistrationPayload())
        ->assertCreated()
        ->assertJsonPath('status', 'pending');

    $user = User::where('email', 'alex@example.com')->firstOrFail();
    expect($user->status)->toBe(UserStatus::Pending);
    expect($user->role->slug)->toBe('teacher');

    Notification::assertSentTo($user, RegistrationSubmitted::class);
    expect(ActivityLog::where('action', 'registered')->exists())->toBeTrue();
});

it('creates a pending registration request for a technician', function () {
    $this->postJson('/api/register', validRegistrationPayload([
        'email' => 'tech@example.com',
        'role' => 'technician',
    ]))->assertCreated();

    expect(User::where('email', 'tech@example.com')->first()->role->slug)->toBe('technician');
});

it('a pending registrant cannot authenticate', function () {
    $this->postJson('/api/register', validRegistrationPayload())->assertCreated();

    $this->postJson('/api/login', [
        'email' => 'alex@example.com',
        'password' => 'Str0ng-Passw0rd!',
    ])->assertStatus(403)->assertJsonPath('code', 'pending');
});

it('never allows self-registration as an administrator', function () {
    $this->postJson('/api/register', validRegistrationPayload(['role' => 'administrator']))
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('role');

    expect(User::where('email', 'alex@example.com')->exists())->toBeFalse();
});

it('rejects a duplicate email', function () {
    userWithRole('teacher', ['email' => 'alex@example.com']);

    $this->postJson('/api/register', validRegistrationPayload())
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('email');
});

it('enforces the password policy on registration', function (string $password) {
    $this->postJson('/api/register', validRegistrationPayload([
        'password' => $password,
        'password_confirmation' => $password,
    ]))->assertStatus(422)->assertJsonValidationErrorFor('password');
})->with([
    'too short' => 'Ab1!x',
    'too few classes' => 'alllowercaseletters',
    'common password' => 'Password123',
]);
