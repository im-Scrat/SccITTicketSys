<?php

declare(strict_types=1);

use App\Http\Middleware\AuthenticateSession;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

/**
 * WP-A — **who may authorize a private broadcast channel**, and what the SPA
 * is told about the socket.
 *
 * `/broadcasting/auth` is the only gate between a WebSocket and whatever a
 * private channel carries: Reverb delivers to any socket that presents a
 * signature this endpoint issued. So it is tested the way every other module's
 * authorization is — the refusals, role by role, not just the happy path.
 *
 * ── Why the channels live in this file ─────────────────────────────────────
 * routes/channels.php holds no channels until a feature needs one (WP-E adds
 * the floor-plan channels). These tests register their own, so they assert the
 * infrastructure — route, middleware floor, signature — independently of any
 * feature's channel rule.
 *
 * ── Why the real Reverb broadcaster ────────────────────────────────────────
 * phpunit.xml pins BROADCAST_CONNECTION=null, whose auth() returns nothing at
 * all. Swapping in the reverb driver with throwaway credentials exercises the
 * actual signing path (it is local HMAC — no socket server is contacted), so a
 * 200 here means a signature Reverb would accept.
 */
const TEST_REVERB_KEY = 'test-reverb-key';
const TEST_REVERB_SECRET = 'test-reverb-secret-never-sent';
const TEST_REVERB_APP_ID = '424242';
const ADMIN_CHANNEL = 'wp-a.test.administrators';
const SOCKET_ID = '1234.5678';

beforeEach(function (): void {
    seedRbac();

    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => TEST_REVERB_KEY,
        'broadcasting.connections.reverb.secret' => TEST_REVERB_SECRET,
        'broadcasting.connections.reverb.app_id' => TEST_REVERB_APP_ID,
        'broadcasting.connections.reverb.options' => [
            'host' => 'reverb', 'port' => 6001, 'scheme' => 'http', 'useTLS' => false,
        ],
    ]);
    Broadcast::forgetDrivers();

    // Counts callback invocations so a refusal can be shown to happen BEFORE
    // the channel rule runs, not merely because the rule said no.
    $this->callbackRuns = 0;
    Broadcast::channel(ADMIN_CHANNEL, function (User $user): bool {
        $this->callbackRuns++;

        return $user->isAdministrator();
    });
});

function authorizeChannel(string $channel = ADMIN_CHANNEL): TestResponse
{
    return test()->postJson('/broadcasting/auth', [
        'socket_id' => SOCKET_ID,
        'channel_name' => "private-{$channel}",
    ]);
}

// ── Route + middleware floor ────────────────────────────────────────────────

it('registers /broadcasting/auth behind the same floor as the feature API routes', function (): void {
    $route = Route::getRoutes()->match(Request::create('/broadcasting/auth', 'POST'));

    // The installer's `channels:` shorthand would put this in `web` with none of
    // these; this is the assertion that catches that regression.
    expect($route->gatherMiddleware())->toContain(
        'api',
        'auth:sanctum',
        'active',
        AuthenticateSession::class,
        'password.current',
    )->not->toContain('web');
});

// ── Refusals ────────────────────────────────────────────────────────────────

it('refuses an unauthenticated client before any channel rule runs', function (): void {
    authorizeChannel()
        ->assertUnauthorized()
        ->assertHeader('Content-Type', 'application/json');

    expect($this->callbackRuns)->toBe(0);
});

it('refuses a teacher the administrator channel', function (): void {
    $this->actingAs(userWithRole('teacher'));

    authorizeChannel()->assertForbidden()->assertHeader('Content-Type', 'application/json');
    expect($this->callbackRuns)->toBe(1);
});

it('refuses a technician the administrator channel', function (): void {
    $this->actingAs(userWithRole('technician'));

    authorizeChannel()->assertForbidden()->assertHeader('Content-Type', 'application/json');
    expect($this->callbackRuns)->toBe(1);
});

it('refuses a suspended administrator before the channel rule runs', function (): void {
    $this->actingAs(userWithRole('administrator', ['status' => 'suspended']));

    authorizeChannel()->assertForbidden();
    expect($this->callbackRuns)->toBe(0);
});

it('refuses an administrator under a forced password reset', function (): void {
    $this->actingAs(userWithRole('administrator', ['force_password_reset' => true]));

    authorizeChannel()->assertForbidden()->assertJsonPath('code', 'password_reset_required');
    expect($this->callbackRuns)->toBe(0);
});

it('refuses a channel that no rule was ever registered for', function (): void {
    $this->actingAs(userWithRole('administrator'));

    authorizeChannel('wp-a.test.unregistered')->assertForbidden();
});

// ── The one success ─────────────────────────────────────────────────────────

it('signs an authorized subscription with the configured secret', function (): void {
    $this->actingAs(userWithRole('administrator'));

    $response = authorizeChannel()
        ->assertOk()
        ->assertHeader('Content-Type', 'application/json');

    $expected = hash_hmac('sha256', SOCKET_ID.':private-'.ADMIN_CHANNEL, TEST_REVERB_SECRET);

    expect($response->json('auth'))->toBe(TEST_REVERB_KEY.':'.$expected);
    // The secret signs; it is never sent.
    expect($response->getContent())->not->toContain(TEST_REVERB_SECRET);
});

// ── GET /api/broadcasting/config ────────────────────────────────────────────

it('refuses the socket configuration to an unauthenticated client', function (): void {
    $this->getJson('/api/broadcasting/config')->assertUnauthorized();
});

it('gives a signed-in user the public key and nothing else', function (string $role): void {
    $this->actingAs(userWithRole($role));

    $response = $this->getJson('/api/broadcasting/config')->assertOk();

    expect($response->json())->toBe(['key' => TEST_REVERB_KEY]);

    // Asserted against the encoded payload, so a field added to the controller
    // later cannot slip the secret or app id out unnoticed.
    expect($response->getContent())
        ->not->toContain(TEST_REVERB_SECRET)
        ->not->toContain(TEST_REVERB_APP_ID);
})->with(['administrator', 'technician', 'teacher']);

it('withholds the socket configuration under a forced password reset', function (): void {
    $this->actingAs(userWithRole('teacher', ['force_password_reset' => true]));

    $this->getJson('/api/broadcasting/config')->assertForbidden();
});

it('answers 503 rather than a dead key when broadcasting is not Reverb', function (): void {
    config(['broadcasting.default' => 'null']);
    $this->actingAs(userWithRole('teacher'));

    $this->getJson('/api/broadcasting/config')
        ->assertStatus(503)
        ->assertJsonMissingPath('key');
});
