<?php

declare(strict_types=1);

use App\Domains\Identity\Notifications\RegistrationApproved;
use App\Domains\Identity\Notifications\RegistrationRejected;
use App\Domains\Identity\Services\LoginThrottle;
use App\Models\LoginHistory;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(fn () => seedRbac());

it('sets and clears the force-password-reset flag', function () {
    $admin = userWithRole('administrator');
    $user = userWithRole('teacher');

    $this->actingAs($admin)->postJson("/api/admin/users/{$user->uuid}/force-password-reset")->assertOk();
    expect($user->fresh()->force_password_reset)->toBeTrue();

    $this->actingAs($admin)->postJson("/api/admin/users/{$user->uuid}/force-password-reset", ['required' => false])->assertOk();
    expect($user->fresh()->force_password_reset)->toBeFalse();
});

it('blocks feature routes under a forced reset and clears it on password change', function () {
    $admin = userWithRole('administrator', ['force_password_reset' => true]);

    $this->actingAs($admin)->getJson('/api/admin/users')
        ->assertForbidden()
        ->assertJsonPath('code', 'password_reset_required');

    // The principal endpoint stays reachable so the SPA can route to the reset.
    $this->actingAs($admin)->getJson('/api/user')->assertOk();

    $this->actingAs($admin)->putJson('/api/password', [
        'current_password' => 'password',
        'password' => 'Str0ng-P@ssw0rd', 'password_confirmation' => 'Str0ng-P@ssw0rd',
    ])->assertOk();

    expect($admin->fresh()->force_password_reset)->toBeFalse()
        ->and($admin->fresh()->password_changed_at)->not->toBeNull();

    $this->actingAs($admin->fresh())->getJson('/api/admin/users')->assertOk();
});

it('unlocks a locked account by clearing its throttle keys', function () {
    $admin = userWithRole('administrator');
    $user = userWithRole('teacher');
    $ip = '203.0.113.9';

    LoginHistory::query()->create([
        'user_id' => $user->id, 'login_at' => now(), 'ip_address' => $ip, 'login_status' => 'failed',
    ]);

    $key = LoginThrottle::key($user->email, $ip);
    for ($i = 0; $i < 5; $i++) {
        RateLimiter::hit($key, 900);
    }
    expect(RateLimiter::tooManyAttempts($key, 5))->toBeTrue();

    $this->actingAs($admin)->postJson("/api/admin/users/{$user->uuid}/unlock")->assertOk();

    expect(RateLimiter::tooManyAttempts($key, 5))->toBeFalse();
    $this->assertDatabaseHas('activity_logs', ['action' => 'account_unlocked', 'subject_id' => $user->id]);
});

it('sends an administrator-initiated password reset email', function () {
    $admin = userWithRole('administrator');
    $user = userWithRole('teacher');

    $this->actingAs($admin)->postJson("/api/admin/users/{$user->uuid}/send-password-reset")->assertOk();

    $this->assertDatabaseHas('activity_logs', ['action' => 'password_reset_email_resent', 'subject_id' => $user->id]);
});

it('resends approval and rejection emails only for the right status', function () {
    Notification::fake();
    $admin = userWithRole('administrator');
    $active = userWithRole('teacher', ['status' => 'active']);
    $rejected = userWithRole('teacher', ['status' => 'rejected']);

    $this->actingAs($admin)->postJson("/api/admin/users/{$active->uuid}/resend-approval")->assertOk();
    Notification::assertSentTo($active, RegistrationApproved::class);

    $this->actingAs($admin)->postJson("/api/admin/users/{$rejected->uuid}/resend-rejection")->assertOk();
    Notification::assertSentTo($rejected, RegistrationRejected::class);

    // Wrong status is refused.
    $this->actingAs($admin)->postJson("/api/admin/users/{$active->uuid}/resend-rejection")->assertStatus(422);
    $this->actingAs($admin)->postJson("/api/admin/users/{$rejected->uuid}/resend-approval")->assertStatus(422);
});
