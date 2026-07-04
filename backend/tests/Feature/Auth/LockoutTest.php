<?php

declare(strict_types=1);

use App\Domains\Identity\Notifications\AccountLocked;
use App\Enums\LoginStatus;
use App\Models\LoginHistory;
use Illuminate\Support\Facades\Notification;

beforeEach(fn () => seedRbac());

it('locks the account after the configured number of failed attempts', function () {
    Notification::fake();
    config(['security.login.max_attempts' => 5]);

    $user = userWithRole('teacher', [
        'email' => 'lock@example.com',
        'password' => 'Str0ng-Passw0rd!',
    ]);

    // First four wrong attempts are ordinary failures.
    foreach (range(1, 4) as $i) {
        $this->postJson('/api/login', ['email' => 'lock@example.com', 'password' => 'wrong'])
            ->assertStatus(422);
    }

    // The fifth crosses the threshold → lockout (429) + one-time notification.
    $this->postJson('/api/login', ['email' => 'lock@example.com', 'password' => 'wrong'])
        ->assertStatus(429);

    Notification::assertSentTo($user, AccountLocked::class);
    expect(LoginHistory::where('login_status', LoginStatus::LockedOut->value)->exists())->toBeTrue();

    // Even the correct password is refused while locked out.
    $this->postJson('/api/login', ['email' => 'lock@example.com', 'password' => 'Str0ng-Passw0rd!'])
        ->assertStatus(429);
});
