<?php

declare(strict_types=1);

use Database\Seeders\SystemSettingSeeder;
use Database\Seeders\TicketLookupSeeder;

/**
 * The technician workspace under a forced password reset (pre-commit fix pass,
 * Phase 2.9).
 *
 * Reported on :8081 as seven separate page failures. They were one: an
 * administrator had set `force_password_reset`, and `password.current`
 * refused every feature request while the client rendered the shell anyway.
 * The server side was right and stays as it is — these tests pin it — and
 * prove that the way out (`/user`, `PUT /password`) stays open and actually
 * reopens every page the technician uses.
 */

/** The GET each technician page loads first. */
const TECHNICIAN_PAGE_ENDPOINTS = [
    'dashboard' => '/api/dashboard/widgets',
    'my work' => '/api/tickets/assigned',
    'completed work' => '/api/tickets/assigned/history',
    'maintenance' => '/api/maintenance',
    'my submissions' => '/api/technician/submissions',
    'my assets' => '/api/my/assets',
    'announcements' => '/api/announcements',
];

beforeEach(function () {
    seedRbac();
    $this->seed(TicketLookupSeeder::class);
    $this->seed(SystemSettingSeeder::class);

    $this->technician = userWithRole('technician', [
        'password' => 'Old-Passw0rd-123',
        'force_password_reset' => true,
    ]);
});

it('refuses every technician page with the reset code while the flag is set', function () {
    foreach (TECHNICIAN_PAGE_ENDPOINTS as $page => $uri) {
        $this->actingAs($this->technician)->getJson($uri)
            ->assertForbidden()
            ->assertJsonPath('code', 'password_reset_required');
    }
});

it('keeps the principal readable, so the client can see the flag', function () {
    $this->actingAs($this->technician)->getJson('/api/user')
        ->assertOk()
        ->assertJsonPath('data.force_password_reset', true);
});

it('reopens every technician page once the password is changed', function () {
    $this->actingAs($this->technician)->putJson('/api/password', [
        'current_password' => 'Old-Passw0rd-123',
        'password' => 'New-Passw0rd-456',
        'password_confirmation' => 'New-Passw0rd-456',
    ])->assertOk();

    expect($this->technician->refresh()->force_password_reset)->toBeFalse();

    foreach (TECHNICIAN_PAGE_ENDPOINTS as $page => $uri) {
        $status = $this->actingAs($this->technician->refresh())->getJson($uri)->status();
        expect($status)->toBe(200, "{$page} ({$uri}) returned {$status}");
    }
});

it('does not let a wrong current password clear the flag', function () {
    $this->actingAs($this->technician)->putJson('/api/password', [
        'current_password' => 'not-the-password',
        'password' => 'New-Passw0rd-456',
        'password_confirmation' => 'New-Passw0rd-456',
    ])->assertUnprocessable();

    expect($this->technician->refresh()->force_password_reset)->toBeTrue();
});
