<?php

declare(strict_types=1);

use App\Domains\Administration\Services\NotificationPreferences;
use App\Domains\KnowledgeBase\Events\AiAnalysisReady;
use App\Domains\Tickets\Notifications\TicketEscalatedNotification;
use App\Enums\AnnouncementAudience;
use App\Enums\NotificationChannel;
use App\Enums\NotificationTopic;
use App\Models\Announcement;
use App\Models\User;
use Database\Seeders\TicketLookupSeeder;
use Illuminate\Notifications\Events\BroadcastNotificationCreated;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
 * Phase 2.9 — realtime notifications without cross-user leakage.
 *
 * Each realtime signal goes to its recipient's own private `users.{uuid}`
 * channel and nowhere else; the channel authorizes exactly that user; the
 * signal carries no content (the client refetches the owner-scoped
 * `/notifications`). Announcements reach "everyone" as one private copy per
 * recipient, never through a shared channel.
 *
 * Channel authorization uses the real Reverb signer with throwaway
 * credentials (no socket server is contacted), re-requiring the real
 * routes/channels.php — the same technique as FloorPlanBroadcastAuthorizationTest.
 */
const ISOLATION_SOCKET_ID = '7777.4242';

beforeEach(function (): void {
    seedRbac();
    $this->seed(TicketLookupSeeder::class);
    Mail::fake();

    $this->teacherA = userWithRole('teacher');
    $this->teacherB = userWithRole('teacher');
    $this->technician = userWithRole('technician');
    $this->admin = userWithRole('administrator');
});

function useReverbSigner(): void
{
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-iso-key',
        'broadcasting.connections.reverb.secret' => 'test-iso-secret-never-sent',
        'broadcasting.connections.reverb.app_id' => '515151',
        'broadcasting.connections.reverb.options' => [
            'host' => 'reverb', 'port' => 6001, 'scheme' => 'http', 'useTLS' => false,
        ],
    ]);
    Broadcast::forgetDrivers();
    require base_path('routes/channels.php');
}

function authorizeUserChannel(mixed $test, ?User $as, string $uuid): TestResponse
{
    $request = $as !== null ? $test->actingAs($as) : $test;

    return $request->postJson('/broadcasting/auth', [
        'socket_id' => ISOLATION_SOCKET_ID,
        'channel_name' => "private-users.{$uuid}",
    ]);
}

/** @return list<string> channel names each captured broadcast notification goes to */
function broadcastChannels(): array
{
    $channels = [];

    Event::assertDispatched(BroadcastNotificationCreated::class, function (BroadcastNotificationCreated $event) use (&$channels): bool {
        foreach ($event->broadcastOn() as $channel) {
            $channels[] = $channel->name;
        }

        return true;
    });

    return $channels;
}

/* ------------------------------------------------------- channel auth */

it('lets a user subscribe to their own channel', function (): void {
    useReverbSigner();

    authorizeUserChannel($this, $this->teacherA, $this->teacherA->uuid)->assertOk()->assertJsonStructure(['auth']);
});

it('refuses a teacher subscribing to another teacher’s channel', function (): void {
    useReverbSigner();

    authorizeUserChannel($this, $this->teacherB, $this->teacherA->uuid)->assertForbidden();
});

it('refuses a technician subscribing to another technician’s channel', function (): void {
    useReverbSigner();
    $other = userWithRole('technician');

    authorizeUserChannel($this, $this->technician, $other->uuid)->assertForbidden();
});

it('refuses even an administrator subscribing to a teacher’s channel', function (): void {
    useReverbSigner();

    authorizeUserChannel($this, $this->admin, $this->teacherA->uuid)->assertForbidden();
});

it('refuses an unknown uuid exactly like a foreign one', function (): void {
    useReverbSigner();

    authorizeUserChannel($this, $this->teacherA, (string) Str::uuid())->assertForbidden();
});

it('refuses an unauthenticated subscription', function (): void {
    useReverbSigner();

    authorizeUserChannel($this, null, $this->teacherA->uuid)->assertUnauthorized();
});

it('no longer exposes the framework’s enumerable id channel', function (): void {
    expect($this->teacherA->receivesBroadcastNotificationsOn())->toBe('users.'.$this->teacherA->uuid);
});

/* ------------------------------------------------ notification signals */

it('broadcasts a notification only on its recipient’s own channel', function (): void {
    Event::fake([BroadcastNotificationCreated::class]);
    $ticket = ticketFor($this->teacherA, 'open', ['title' => 'Projector in Lab 4 is dead']);

    $this->admin->notify(new TicketEscalatedNotification($ticket));

    expect(broadcastChannels())->toBe(['private-users.'.$this->admin->uuid]);
});

it('carries a signal, not the notification’s content', function (): void {
    Event::fake([BroadcastNotificationCreated::class]);
    $ticket = ticketFor($this->teacherA, 'open', ['title' => 'Projector in Lab 4 is dead']);

    $this->admin->notify(new TicketEscalatedNotification($ticket));

    Event::assertDispatched(BroadcastNotificationCreated::class, function (BroadcastNotificationCreated $event) use ($ticket): bool {
        $encoded = json_encode($event->broadcastWith(), JSON_THROW_ON_ERROR);

        expect(array_keys($event->broadcastWith()))->toEqualCanonicalizing(['topic', 'notification_type', 'id', 'type'])
            ->and($event->broadcastWith()['type'])->toBe('notification.created')
            ->and($encoded)->not->toContain($ticket->title)
            ->and($encoded)->not->toContain($ticket->ticket_number)
            ->and($encoded)->not->toContain($ticket->uuid);

        return true;
    });
});

it('sends no signal to a recipient who switched in-app off for that topic', function (): void {
    Event::fake([BroadcastNotificationCreated::class]);
    app(NotificationPreferences::class)->set(
        $this->admin,
        NotificationChannel::InApp,
        NotificationTopic::TicketEscalated->type(),
        false,
    );

    $this->admin->notify(new TicketEscalatedNotification(ticketFor($this->teacherA)));

    Event::assertNotDispatched(BroadcastNotificationCreated::class);
});

/* --------------------------------------------------------- announcements */

it('fans an all-audience announcement out as one private signal per recipient, author excluded', function (): void {
    Event::fake([BroadcastNotificationCreated::class]);
    $announcement = Announcement::factory()->create(['audience' => AnnouncementAudience::All, 'is_active' => false]);

    $this->actingAs($this->admin)
        ->postJson('/api/admin/announcements/'.$announcement->uuid.'/publish')
        ->assertOk();

    $channels = broadcastChannels();
    expect($channels)->toContain('private-users.'.$this->teacherA->uuid)
        ->and($channels)->toContain('private-users.'.$this->teacherB->uuid)
        ->and($channels)->toContain('private-users.'.$this->technician->uuid)
        ->and($channels)->not->toContain('private-users.'.$this->admin->uuid)
        ->and(collect($channels)->every(fn (string $name): bool => str_starts_with($name, 'private-users.')))->toBeTrue()
        ->and($channels)->toBe(array_values(array_unique($channels)));
});

it('does not signal technicians about a teachers-only announcement', function (): void {
    Event::fake([BroadcastNotificationCreated::class]);
    $announcement = Announcement::factory()->create(['audience' => AnnouncementAudience::Teachers, 'is_active' => false]);

    $this->actingAs($this->admin)
        ->postJson('/api/admin/announcements/'.$announcement->uuid.'/publish')
        ->assertOk();

    expect(broadcastChannels())->not->toContain('private-users.'.$this->technician->uuid);
});

/* ------------------------------------------------------- AI analysis ready */

it('addresses "AI analysis ready" to the reporter’s own channel with only the ticket id', function (): void {
    $event = new AiAnalysisReady($this->teacherA->uuid, 'ticket-uuid-1');

    expect(collect($event->broadcastOn())->map->name->all())->toBe(['private-users.'.$this->teacherA->uuid])
        ->and($event->broadcastWith())->toBe(['ticket' => 'ticket-uuid-1'])
        ->and($event->broadcastQueue())->toBe('realtime');
});
