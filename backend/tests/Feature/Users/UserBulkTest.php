<?php

declare(strict_types=1);

use App\Domains\Identity\Notifications\AdminMessage;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

beforeEach(fn () => seedRbac());

it('bulk-activates selected users', function () {
    $admin = userWithRole('administrator');
    $users = collect(range(1, 3))->map(fn () => userWithRole('teacher', ['status' => 'inactive']));

    $this->actingAs($admin)->postJson('/api/admin/users/bulk', [
        'action' => 'activate',
        'ids' => $users->pluck('uuid')->all(),
    ])->assertOk()->assertJsonPath('count', 3);

    $users->each(fn (User $u) => expect($u->fresh()->status->value)->toBe('active'));
});

it('bulk role assignment updates every selected user', function () {
    $admin = userWithRole('administrator');
    $users = collect(range(1, 2))->map(fn () => userWithRole('teacher'));

    $this->actingAs($admin)->postJson('/api/admin/users/bulk', [
        'action' => 'role', 'role' => 'technician',
        'ids' => $users->pluck('uuid')->all(),
    ])->assertOk();

    $users->each(fn (User $u) => expect($u->fresh()->isAdministrator())->toBeFalse()
        ->and($u->fresh()->role->slug)->toBe('technician'));
});

it('rolls the whole batch back when one target is not permitted', function () {
    // Actor can suspend generally, but the batch includes the last admin.
    $actor = userWithRole('technician');
    $actor->directPermissions()->attach(Permission::where('name', 'users.update')->value('id'), ['grant_type' => 'grant']);
    $soleAdmin = userWithRole('administrator');
    $ok = userWithRole('teacher');

    $this->actingAs($actor)->postJson('/api/admin/users/bulk', [
        'action' => 'suspend',
        'ids' => [$ok->uuid, $soleAdmin->uuid],
    ])->assertForbidden();

    // Transactional: the permitted target was NOT suspended because the batch aborted.
    expect($ok->fresh()->status->value)->toBe('active')
        ->and($soleAdmin->fresh()->status->value)->toBe('active');
});

it('bulk-notifies selected users', function () {
    Notification::fake();
    $admin = userWithRole('administrator');
    $users = collect(range(1, 2))->map(fn () => userWithRole('teacher'));

    $this->actingAs($admin)->postJson('/api/admin/users/bulk', [
        'action' => 'notify', 'subject' => 'Scheduled maintenance', 'message' => 'The lab is closed Friday.',
        'ids' => $users->pluck('uuid')->all(),
    ])->assertOk();

    $users->each(fn (User $u) => Notification::assertSentTo($u, AdminMessage::class));
});

it('records a bulk-action audit summary', function () {
    $admin = userWithRole('administrator');
    $user = userWithRole('teacher', ['status' => 'inactive']);

    $this->actingAs($admin)->postJson('/api/admin/users/bulk', [
        'action' => 'activate', 'ids' => [$user->uuid],
    ])->assertOk();

    $this->assertDatabaseHas('activity_logs', ['action' => 'bulk_action', 'user_id' => $admin->id]);
});

it('forbids bulk operations without the permission', function () {
    $teacher = userWithRole('teacher');
    $target = userWithRole('teacher');

    $this->actingAs($teacher)->postJson('/api/admin/users/bulk', [
        'action' => 'activate', 'ids' => [$target->uuid],
    ])->assertForbidden();
});
