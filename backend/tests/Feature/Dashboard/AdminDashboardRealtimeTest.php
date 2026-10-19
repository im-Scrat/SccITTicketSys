<?php

declare(strict_types=1);

use App\Domains\Analytics\Events\AdminDashboardChanged;
use App\Domains\Analytics\Services\AdminDashboardSignal;
use App\Domains\Analytics\Services\DashboardService;
use App\Enums\PermissionGrantType;
use App\Models\AiAnalysisLog;
use Database\Seeders\SystemSettingSeeder;
use Database\Seeders\TicketLookupSeeder;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

/*
 * Phase 2.9 — the live administrator dashboard.
 *
 * Domain changes raise a thin `dashboard.changed` signal (kind + uuid) on the
 * private `admin.dashboard` channel, which only Administrators may subscribe
 * to; the dashboard refetches through its authorized endpoints. Each signal
 * also bumps the dashboard cache version so the refetch is fresh.
 */
const ADMIN_DASH_SOCKET_ID = '9191.2020';

beforeEach(function (): void {
    seedRbac();
    $this->seed(TicketLookupSeeder::class);
    $this->seed(SystemSettingSeeder::class);
    Queue::fake();

    $this->admin = userWithRole('administrator');
    $this->teacher = userWithRole('teacher');
    $this->technician = userWithRole('technician');
});

function authorizeAdminDashboard(mixed $test, mixed $user): TestResponse
{
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-dash-key',
        'broadcasting.connections.reverb.secret' => 'test-dash-secret-never-sent',
        'broadcasting.connections.reverb.app_id' => '626262',
        'broadcasting.connections.reverb.options' => [
            'host' => 'reverb', 'port' => 6001, 'scheme' => 'http', 'useTLS' => false,
        ],
    ]);
    Broadcast::forgetDrivers();
    require base_path('routes/channels.php');

    return $test->actingAs($user)->postJson('/broadcasting/auth', [
        'socket_id' => ADMIN_DASH_SOCKET_ID,
        'channel_name' => 'private-'.AdminDashboardChanged::CHANNEL,
    ]);
}

/* ------------------------------------------------------- channel auth */

it('lets an administrator subscribe to the dashboard channel', function (): void {
    authorizeAdminDashboard($this, $this->admin)->assertOk();
});

it('refuses a teacher subscribing to the dashboard channel', function (): void {
    authorizeAdminDashboard($this, $this->teacher)->assertForbidden();
});

it('refuses a technician subscribing to the dashboard channel', function (): void {
    authorizeAdminDashboard($this, $this->technician)->assertForbidden();
});

it('refuses a technician even with every report permission granted', function (): void {
    givePermission($this->technician, 'reports.view', PermissionGrantType::Grant);
    givePermission($this->technician, 'tickets.assign', PermissionGrantType::Grant);

    authorizeAdminDashboard($this, $this->technician)->assertForbidden();
});

/* ------------------------------------------------------------ signals */

it('signals a new ticket with its uuid and nothing else', function (): void {
    Event::fake([AdminDashboardChanged::class]);

    $this->actingAs($this->teacher)->postJson('/api/tickets', [
        'title' => 'Printer jams on every page',
        'description' => 'The staff-room printer jams on every single page.',
        'category' => 'hardware',
    ])->assertCreated();

    Event::assertDispatched(AdminDashboardChanged::class, function (AdminDashboardChanged $event): bool {
        return $event->kind === 'ticket.created'
            && array_keys($event->broadcastWith()) === ['kind', 'subject']
            && collect($event->broadcastOn())->map->name->all() === ['private-admin.dashboard']
            && ! str_contains((string) json_encode($event->broadcastWith()), 'Printer');
    });
});

it('signals a teacher’s NOT FIXED escalation', function (): void {
    Event::fake([AdminDashboardChanged::class]);
    $ticket = ticketFor($this->teacher);
    AiAnalysisLog::factory()->create(['ticket_id' => $ticket->id]);

    $this->actingAs($this->teacher)->postJson("/api/tickets/{$ticket->uuid}/not-fixed")->assertSuccessful();

    Event::assertDispatched(AdminDashboardChanged::class, fn (AdminDashboardChanged $event): bool => $event->kind === 'ticket.escalated' && $event->subject === $ticket->uuid);
});

it('signals a vote, which has no domain event of its own', function (): void {
    Event::fake([AdminDashboardChanged::class]);
    $ticket = ticketFor(userWithRole('teacher'));

    $this->actingAs($this->teacher)->postJson("/api/tickets/{$ticket->uuid}/vote")->assertSuccessful();

    Event::assertDispatched(AdminDashboardChanged::class, fn (AdminDashboardChanged $event): bool => $event->kind === 'ticket.voted' && $event->subject === $ticket->uuid);
});

/* ------------------------------------------------------- cache version */

it('moves every dashboard cache key when a change is signalled', function (): void {
    $service = app(DashboardService::class);
    $before = $service->cacheKey($this->admin);

    app(AdminDashboardSignal::class)->send('ticket.created', 'x');

    expect($service->cacheKey($this->admin))->not->toBe($before);
});

it('does not move the version for a change that rolls back', function (): void {
    $service = app(DashboardService::class);
    $before = $service->cacheKey($this->admin);

    try {
        DB::transaction(function (): void {
            app(AdminDashboardSignal::class)->send('ticket.created', 'x');

            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect($service->cacheKey($this->admin))->toBe($before);
});
