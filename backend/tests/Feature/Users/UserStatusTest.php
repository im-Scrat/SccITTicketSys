<?php

declare(strict_types=1);

use App\Models\Permission;

beforeEach(fn () => seedRbac());

it('activates, suspends, reactivates and deactivates a user', function () {
    $admin = userWithRole('administrator');
    $user = userWithRole('teacher', ['status' => 'inactive']);

    $this->actingAs($admin)->postJson("/api/admin/users/{$user->uuid}/activate")->assertOk();
    expect($user->fresh()->status->value)->toBe('active');

    $this->actingAs($admin)->postJson("/api/admin/users/{$user->uuid}/suspend", ['reason' => 'policy breach'])->assertOk();
    expect($user->fresh()->status->value)->toBe('suspended');

    $this->actingAs($admin)->postJson("/api/admin/users/{$user->uuid}/reactivate")->assertOk();
    expect($user->fresh()->status->value)->toBe('active');

    $this->actingAs($admin)->postJson("/api/admin/users/{$user->uuid}/deactivate")->assertOk();
    expect($user->fresh()->status->value)->toBe('inactive');
});

it('audits a suspension with its reason', function () {
    $admin = userWithRole('administrator');
    $user = userWithRole('teacher');

    $this->actingAs($admin)->postJson("/api/admin/users/{$user->uuid}/suspend", ['reason' => 'policy breach'])->assertOk();

    $this->assertDatabaseHas('activity_logs', [
        'action' => 'account_suspended', 'subject_id' => $user->id,
    ]);
});

it('forbids suspending your own account', function () {
    $admin = userWithRole('administrator');

    $this->actingAs($admin)->postJson("/api/admin/users/{$admin->uuid}/suspend")->assertForbidden();
    expect($admin->fresh()->status->value)->toBe('active');
});

it('forbids suspending the last active administrator', function () {
    // A non-admin actor granted users.update, and the only admin as the target.
    $actor = userWithRole('technician');
    $actor->directPermissions()->attach(Permission::where('name', 'users.update')->value('id'), ['grant_type' => 'grant']);
    $soleAdmin = userWithRole('administrator');

    $this->actingAs($actor)->postJson("/api/admin/users/{$soleAdmin->uuid}/suspend")->assertForbidden();
    expect($soleAdmin->fresh()->status->value)->toBe('active');
});

it('allows suspending an administrator when another active admin remains', function () {
    $admin = userWithRole('administrator');
    $other = userWithRole('administrator');

    $this->actingAs($admin)->postJson("/api/admin/users/{$other->uuid}/suspend")->assertOk();
    expect($other->fresh()->status->value)->toBe('suspended');
});

it('clears rejection metadata when re-activating a rejected account', function () {
    $admin = userWithRole('administrator');
    $user = userWithRole('teacher', ['status' => 'rejected', 'rejection_reason' => 'nope', 'rejected_at' => now()]);

    $this->actingAs($admin)->postJson("/api/admin/users/{$user->uuid}/activate")->assertOk();

    $fresh = $user->fresh();
    expect($fresh->status->value)->toBe('active')
        ->and($fresh->rejection_reason)->toBeNull()
        ->and($fresh->rejected_at)->toBeNull();
});
