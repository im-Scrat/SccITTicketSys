<?php

declare(strict_types=1);

use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketPriority;
use App\Models\TicketStatus;
use App\Models\User;

beforeEach(fn () => seedRbac());

it('creates a user with created_by, provenance and a hashed password', function () {
    $admin = userWithRole('administrator');

    $response = $this->actingAs($admin)->postJson('/api/admin/users', [
        'role' => 'technician',
        'first_name' => 'Nova', 'last_name' => 'Rhodes',
        'email' => 'nova.rhodes@example.com',
        'password' => 'Str0ng-P@ssw0rd', 'password_confirmation' => 'Str0ng-P@ssw0rd',
    ])->assertCreated();

    $response->assertJsonPath('data.email', 'nova.rhodes@example.com');

    $user = User::query()->where('email', 'nova.rhodes@example.com')->firstOrFail();
    expect($user->created_by)->toBe($admin->id)
        ->and($user->registration_source)->toBe('admin')
        ->and($user->status->value)->toBe('active')
        ->and($user->password)->not->toBe('Str0ng-P@ssw0rd');
});

it('rejects a duplicate email and a weak password', function () {
    $admin = userWithRole('administrator');
    userWithRole('teacher', ['email' => 'taken@example.com']);

    $this->actingAs($admin)->postJson('/api/admin/users', [
        'role' => 'teacher', 'first_name' => 'A', 'last_name' => 'B',
        'email' => 'taken@example.com',
        'password' => 'password', 'password_confirmation' => 'password',
    ])->assertStatus(422)->assertJsonValidationErrors(['email', 'password']);
});

it('updates a profile and stamps updated_by', function () {
    $admin = userWithRole('administrator');
    $user = userWithRole('teacher', ['first_name' => 'Old']);

    $this->actingAs($admin)->putJson("/api/admin/users/{$user->uuid}", [
        'first_name' => 'New', 'last_name' => $user->last_name, 'email' => $user->email,
    ])->assertOk()->assertJsonPath('data.first_name', 'New');

    expect($user->fresh()->updated_by)->toBe($admin->id);
});

it('archives a user as a soft delete (never a hard delete)', function () {
    $admin = userWithRole('administrator');
    $user = userWithRole('teacher');

    $this->actingAs($admin)->deleteJson("/api/admin/users/{$user->uuid}")->assertOk();

    expect(User::query()->where('id', $user->id)->exists())->toBeFalse()
        ->and(User::withTrashed()->where('id', $user->id)->exists())->toBeTrue();
});

it('preserves attributions and foreign keys when archived', function () {
    $admin = userWithRole('administrator');
    $reporter = userWithRole('teacher');

    $ticket = Ticket::factory()->create([
        'reporter_id' => $reporter->id,
        'category_id' => TicketCategory::factory(),
        'priority_id' => TicketPriority::factory(),
        'current_status_id' => TicketStatus::factory(),
    ]);

    $this->actingAs($admin)->deleteJson("/api/admin/users/{$reporter->uuid}")->assertOk();

    // The ticket and its attribution survive the archive.
    expect($ticket->fresh()->reporter_id)->toBe($reporter->id);
});

it('restores an archived user', function () {
    $admin = userWithRole('administrator');
    $user = userWithRole('teacher');
    $user->delete();

    $this->actingAs($admin)->postJson("/api/admin/users/{$user->uuid}/restore")->assertOk();

    expect(User::query()->where('id', $user->id)->exists())->toBeTrue();
});

it('records an audit entry for every write', function () {
    $admin = userWithRole('administrator');
    $user = userWithRole('teacher');

    $this->actingAs($admin)->putJson("/api/admin/users/{$user->uuid}", [
        'first_name' => 'Changed', 'last_name' => $user->last_name, 'email' => $user->email,
    ])->assertOk();

    $this->assertDatabaseHas('activity_logs', [
        'action' => 'user_updated', 'user_id' => $admin->id, 'subject_id' => $user->id,
    ]);
});
