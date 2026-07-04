<?php

declare(strict_types=1);

use App\Domains\Identity\Notifications\RegistrationApproved;
use App\Domains\Identity\Notifications\RegistrationRejected;
use App\Enums\UserStatus;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Notification;

beforeEach(fn () => seedRbac());

it('lists pending registrations for an administrator', function () {
    $admin = userWithRole('administrator');
    userWithRole('teacher', ['status' => 'pending']);
    userWithRole('technician', ['status' => 'pending']);
    userWithRole('teacher', ['status' => 'active']); // should not appear

    $this->actingAs($admin)->getJson('/api/admin/registrations')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('approves a pending registration and activates the account', function () {
    Notification::fake();
    $admin = userWithRole('administrator');
    $applicant = userWithRole('teacher', ['status' => 'pending']);

    $this->actingAs($admin)
        ->postJson("/api/admin/registrations/{$applicant->uuid}/approve")
        ->assertOk();

    expect($applicant->fresh()->status)->toBe(UserStatus::Active);
    Notification::assertSentTo($applicant, RegistrationApproved::class);
    expect(ActivityLog::where('action', 'registration_approved')->where('user_id', $admin->id)->exists())->toBeTrue();
});

it('rejects a pending registration with a reason', function () {
    Notification::fake();
    $admin = userWithRole('administrator');
    $applicant = userWithRole('teacher', ['status' => 'pending']);

    $this->actingAs($admin)
        ->postJson("/api/admin/registrations/{$applicant->uuid}/reject", ['reason' => 'Not a staff member.'])
        ->assertOk();

    $fresh = $applicant->fresh();
    expect($fresh->status)->toBe(UserStatus::Rejected);
    expect($fresh->rejection_reason)->toBe('Not a staff member.');
    expect($fresh->rejected_by)->toBe($admin->id);
    expect($fresh->rejected_at)->not->toBeNull();

    Notification::assertSentTo($applicant, RegistrationRejected::class);
    expect(ActivityLog::where('action', 'registration_rejected')->exists())->toBeTrue();
});

it('an approved user can then sign in', function () {
    $admin = userWithRole('administrator');
    $applicant = userWithRole('teacher', [
        'email' => 'applicant@example.com',
        'password' => 'Str0ng-Passw0rd!',
        'status' => 'pending',
    ]);

    $this->actingAs($admin)->postJson("/api/admin/registrations/{$applicant->uuid}/approve")->assertOk();

    $this->postJson('/api/login', [
        'email' => 'applicant@example.com',
        'password' => 'Str0ng-Passw0rd!',
    ])->assertOk();
});

it('forbids a non-administrator from listing registrations', function () {
    $this->actingAs(userWithRole('technician'))
        ->getJson('/api/admin/registrations')
        ->assertForbidden();
});

it('forbids a non-administrator from approving a registration', function () {
    $applicant = userWithRole('teacher', ['status' => 'pending']);

    $this->actingAs(userWithRole('technician'))
        ->postJson("/api/admin/registrations/{$applicant->uuid}/approve")
        ->assertForbidden();
});

it('cannot approve a non-pending account', function () {
    $admin = userWithRole('administrator');
    $active = userWithRole('teacher', ['status' => 'active']);

    $this->actingAs($admin)->postJson("/api/admin/registrations/{$active->uuid}/approve")->assertForbidden();
});
