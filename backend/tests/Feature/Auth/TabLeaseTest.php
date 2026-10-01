<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Per-tab session lease (EnsureTabLeaseIsValid)
|--------------------------------------------------------------------------
| These tests drive the real login endpoint (not actingAs()) because the
| lease only exists on a session that went through AuthService::login() —
| exactly the property the middleware relies on to stay invisible to the rest
| of the suite's actingAs()-based tests (SESSION_DRIVER=array there, so they
| never populate a session at all).
*/

beforeEach(fn () => seedRbac());

function loginAndGetLease(string $email, string $password): string
{
    $response = test()->postJson('/api/login', ['email' => $email, 'password' => $password]);
    $response->assertOk();

    $lease = $response->json('lease');
    expect($lease)->toBeString()->not->toBeEmpty();

    return $lease;
}

it('issues a per-tab lease on login', function () {
    userWithRole('teacher', ['email' => 'teacher@example.com', 'password' => 'Str0ng-Passw0rd!']);

    loginAndGetLease('teacher@example.com', 'Str0ng-Passw0rd!');
});

it('serves a feature route when the correct lease is echoed back', function () {
    userWithRole('teacher', ['email' => 'teacher@example.com', 'password' => 'Str0ng-Passw0rd!']);
    $lease = loginAndGetLease('teacher@example.com', 'Str0ng-Passw0rd!');

    $this->withHeader('X-Tab-Lease', $lease)
        ->getJson('/api/user')
        ->assertOk()
        ->assertJsonPath('data.email', 'teacher@example.com');
});

it('refuses a new tab that shares the cookie but never logged in', function () {
    userWithRole('teacher', ['email' => 'teacher@example.com', 'password' => 'Str0ng-Passw0rd!']);
    loginAndGetLease('teacher@example.com', 'Str0ng-Passw0rd!');

    // Same test client (same cookie jar / session), no X-Tab-Lease header —
    // exactly what a freshly opened tab looks like server-side.
    $this->getJson('/api/user')->assertUnauthorized();
});

it('refuses a forged or stale lease value', function () {
    userWithRole('teacher', ['email' => 'teacher@example.com', 'password' => 'Str0ng-Passw0rd!']);
    loginAndGetLease('teacher@example.com', 'Str0ng-Passw0rd!');

    $this->withHeader('X-Tab-Lease', 'not-the-real-token')
        ->getJson('/api/user')
        ->assertUnauthorized();
});

it('refreshes the lease on a heartbeat and keeps the tab authenticated', function () {
    userWithRole('teacher', ['email' => 'teacher@example.com', 'password' => 'Str0ng-Passw0rd!']);
    $lease = loginAndGetLease('teacher@example.com', 'Str0ng-Passw0rd!');

    $this->withHeader('X-Tab-Lease', $lease)->postJson('/api/auth/heartbeat')->assertNoContent();
    $this->withHeader('X-Tab-Lease', $lease)->getJson('/api/user')->assertOk();
});

it('invalidates the whole session once the lease goes stale, not just the one request', function () {
    config(['security.tab_lease.stale_after_seconds' => 60]);
    userWithRole('teacher', ['email' => 'teacher@example.com', 'password' => 'Str0ng-Passw0rd!']);
    $lease = loginAndGetLease('teacher@example.com', 'Str0ng-Passw0rd!');

    /*
     * Backdate the stored heartbeat rather than travelling Carbon's global
     * clock: the test HTTP client's cookie jar computes the session cookie's
     * own expiry from real wall-clock time at the moment it was set, and
     * faking `now()` forward desyncs that check enough that the client
     * silently drops the cookie and the next call gets issued a brand-new,
     * never-authenticated session instead of reusing the real one — not the
     * scenario under test. Writing the past heartbeat directly into the same
     * session the login just created avoids that entirely.
     */
    session(['tab_lease_last_seen' => now()->subMinutes(5)->timestamp]);

    $this->withHeader('X-Tab-Lease', $lease)->getJson('/api/user')->assertUnauthorized();

    /*
     * Not just this one request refused — the session itself is gone. A real
     * later request (its own fresh PHP-FPM process, never the long-lived
     * PHPUnit process this test runs in) would read this exact row from
     * scratch and find it empty; checking the row directly is what actually
     * proves that, where a third simulated HTTP call in this same process
     * cannot — Sanctum's guard caches its resolved user for the rest of the
     * test process regardless of what a later request's own session lookup
     * would find, an artifact no production request ever hits.
     */
    $remaining = session()->all();
    expect($remaining)->not->toHaveKey('tab_lease');
    expect(array_filter(array_keys($remaining), fn ($key) => str_starts_with($key, 'login_web_')))->toBeEmpty();
});

it('ends the session when the closing tab sends its beacon', function () {
    userWithRole('teacher', ['email' => 'teacher@example.com', 'password' => 'Str0ng-Passw0rd!']);
    $lease = loginAndGetLease('teacher@example.com', 'Str0ng-Passw0rd!');

    $this->postJson('/api/auth/tab-close?lease='.$lease)->assertNoContent();

    $this->withHeader('X-Tab-Lease', $lease)->getJson('/api/user')->assertUnauthorized();
});

it('ignores a tab-close beacon carrying the wrong lease', function () {
    userWithRole('teacher', ['email' => 'teacher@example.com', 'password' => 'Str0ng-Passw0rd!']);
    $lease = loginAndGetLease('teacher@example.com', 'Str0ng-Passw0rd!');

    $this->postJson('/api/auth/tab-close?lease=not-the-real-token')->assertNoContent();

    // The real tab's session must still be alive.
    $this->withHeader('X-Tab-Lease', $lease)->getJson('/api/user')->assertOk();
});

it('leaves actingAs()-based tests untouched', function () {
    $user = userWithRole('technician');

    // No login(), no lease — the established pattern used across the rest of
    // the suite. The middleware must step aside here (see its own docblock).
    $this->actingAs($user)->getJson('/api/user')->assertOk();
});
