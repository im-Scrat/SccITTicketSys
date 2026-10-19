<?php

declare(strict_types=1);

use App\Enums\ActivityAction;
use App\Enums\PermissionGrantType;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Mail;

/**
 * Outgoing-mail diagnostics — Administrator with `system.settings.manage`
 * only, proved role by role. The test message can only ever reach the caller.
 */
beforeEach(function () {
    seedRbac();
    $this->admin = userWithRole('administrator', ['email' => 'admin-owner@example.org']);
    config([
        'mail.mailers.smtp.username' => 'relay-user-for-tests',
        'mail.mailers.smtp.password' => 'relay-password-for-tests',
    ]);
});

it('shows an administrator the configuration without credentials', function () {
    config(['mail.default' => 'smtp']);

    $response = $this->actingAs($this->admin)->getJson('/api/admin/system/mail')
        ->assertOk()
        ->assertJsonPath('data.username_set', true)
        ->assertJsonPath('data.password_set', true);

    expect($response->getContent())
        ->not->toContain('relay-user-for-tests')
        ->not->toContain('relay-password-for-tests');
});

it('refuses technicians and teachers, and guests, on every mail route', function () {
    $routes = [['get', '/api/admin/system/mail'], ['post', '/api/admin/system/mail/check'], ['post', '/api/admin/system/mail/test']];

    foreach ($routes as [$method, $uri]) {
        $this->{$method.'Json'}($uri)->assertUnauthorized();
    }

    foreach (['technician', 'teacher'] as $role) {
        $user = userWithRole($role);
        foreach ($routes as [$method, $uri]) {
            $this->actingAs($user)->{$method.'Json'}($uri)->assertForbidden();
        }
    }

    expect(Mail::mailer()->getSymfonyTransport()->messages())->toHaveCount(0);
});

it('does not open to a technician granted the permission by hand', function () {
    $technician = userWithRole('technician');
    givePermission($technician, 'system.settings.manage');

    $this->actingAs($technician)->getJson('/api/admin/system/mail')->assertForbidden();
});

it('closes to an administrator whose system.settings.manage is denied', function () {
    givePermission($this->admin, 'system.settings.manage', PermissionGrantType::Deny);

    $this->actingAs($this->admin)->getJson('/api/admin/system/mail')->assertForbidden();
});

it('sends the test message to the caller only, ignoring any recipient in the request, and audits it', function () {
    $this->actingAs($this->admin)
        ->postJson('/api/admin/system/mail/test', ['to' => 'someone-else@example.org', 'recipient' => 'victim@example.org'])
        ->assertOk()
        ->assertJsonPath('data.ok', true)
        ->assertJsonPath('data.sent_to', 'admin-owner@example.org');

    $messages = Mail::mailer()->getSymfonyTransport()->messages();
    expect($messages)->toHaveCount(1)
        ->and(array_map(fn ($a) => $a->getAddress(), $messages->first()->getOriginalMessage()->getTo()))->toBe(['admin-owner@example.org']);

    expect(ActivityLog::query()->where('action', ActivityAction::MailTestSent->value)->sole()->properties['outcome'])->toBe('accepted');
});

it('runs the connection test without sending anything, and audits it', function () {
    $this->actingAs($this->admin)->postJson('/api/admin/system/mail/check')
        ->assertOk()
        ->assertJsonPath('data.ok', false)
        ->assertJsonPath('data.outcome', 'not_smtp'); // the test mailer is the array transport

    expect(Mail::mailer()->getSymfonyTransport()->messages())->toHaveCount(0)
        ->and(ActivityLog::query()->where('action', ActivityAction::MailConnectionChecked->value)->exists())->toBeTrue();
});

it('throttles test messages to three an hour per administrator', function () {
    foreach (range(1, 3) as $_) {
        $this->actingAs($this->admin)->postJson('/api/admin/system/mail/test')->assertOk();
    }

    $this->actingAs($this->admin)->postJson('/api/admin/system/mail/test')->assertStatus(429);
    expect(Mail::mailer()->getSymfonyTransport()->messages())->toHaveCount(3);
});
