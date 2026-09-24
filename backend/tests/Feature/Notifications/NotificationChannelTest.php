<?php

declare(strict_types=1);

use App\Domains\Administration\Notifications\Channels\DatabaseChannel;
use App\Domains\Administration\Notifications\ProjectNotification;
use App\Enums\NotificationTopic;
use App\Enums\NotificationType;
use App\Models\Notification as NotificationRecord;
use App\Models\User;
use Illuminate\Notifications\Notification as FrameworkNotification;

/**
 * WP-2.7a — **the project database channel** (SDD DD-52).
 *
 * The driver that exists because Laravel's stock `DatabaseChannel` cannot write
 * to this project's `notifications` table: it wants a morph, a uuid primary key
 * and a class-name `type`, and this schema has a direct `user_id`, a bigint key
 * with a separate public uuid, and a `type` constrained by CHECK to the nine
 * values FR-NOT-005 enumerates.
 *
 * These tests hold the two properties everything else in the layer assumes: the
 * row has the shape the requirements describe, and a retried delivery does not
 * duplicate it.
 */
beforeEach(function (): void {
    seedRbac();
    $this->user = userWithRole('teacher');
    $this->channel = app(DatabaseChannel::class);
});

/** A minimal notification, so the driver is tested and not a real trigger. */
function fakeNotification(?string $dedupe = 'test:fixed-key'): ProjectNotification
{
    return new class($dedupe) extends ProjectNotification
    {
        public function __construct(private readonly ?string $key)
        {
            parent::__construct();
        }

        public function topic(): NotificationTopic
        {
            return NotificationTopic::TicketAssigned;
        }

        public function dedupeKey(): ?string
        {
            return $this->key;
        }

        public function payload(User $notifiable): array
        {
            return [
                'title' => 'A title',
                'message' => 'A message',
                'data' => ['ticket_number' => 'TKT-000001'],
                'action_url' => '/app/tickets/abc',
            ];
        }
    };
}

it('writes a row in the shape the baselined schema describes', function (): void {
    $this->channel->send($this->user, fakeNotification());

    $row = NotificationRecord::query()->sole();

    expect($row->user_id)->toBe($this->user->id)
        // The topic's *type*, not its class name — the CHECK constraint's domain.
        ->and($row->type)->toBe(NotificationType::Assignment)
        ->and($row->title)->toBe('A title')
        ->and($row->message)->toBe('A message')
        ->and($row->action_url)->toBe('/app/tickets/abc')
        ->and($row->read_at)->toBeNull()
        ->and($row->uuid)->not->toBeEmpty()
        ->and($row->created_at)->not->toBeNull();
});

it('carries the trigger identity in data, where the nine-value type column cannot', function (): void {
    $this->channel->send($this->user, fakeNotification());

    expect(NotificationRecord::query()->sole()->data)
        ->toMatchArray([
            'topic' => 'ticket.assigned',
            'ticket_number' => 'TKT-000001',
        ]);
});

/**
 * The idempotency property the whole queued path depends on: a worker that
 * writes the row and dies before acknowledging runs the same job again, carrying
 * the same key.
 */
it('swallows a repeated delivery carrying the same dedupe key', function (): void {
    $first = $this->channel->send($this->user, fakeNotification());
    $second = $this->channel->send($this->user, fakeNotification());

    expect($first)->not->toBeNull()
        ->and($second)->toBeNull()
        ->and(NotificationRecord::query()->count())->toBe(1);
});

/**
 * The key is scoped per user, and this is the case that would break a role
 * audience: one submitted work support request notifies every administrator, and
 * a globally-unique key would let the first one's row suppress all the others.
 */
it('lets two recipients each keep their own row for one event', function (): void {
    $other = userWithRole('teacher');

    $this->channel->send($this->user, fakeNotification());
    $this->channel->send($other, fakeNotification());

    expect(NotificationRecord::query()->count())->toBe(2);
});

it('writes every delivery when no dedupe key is offered', function (): void {
    $this->channel->send($this->user, fakeNotification(null));
    $this->channel->send($this->user, fakeNotification(null));

    expect(NotificationRecord::query()->count())->toBe(2);
});

/**
 * A notification that routes to `database` without extending our base class
 * would otherwise reach a NOT NULL `title` with nothing to put in it — a 500
 * inside a queue worker, which is the worst place to discover it.
 */
it('refuses a notification that is not one of ours', function (): void {
    $foreign = new class extends FrameworkNotification {};

    expect($this->channel->send($this->user, $foreign))->toBeNull()
        ->and(NotificationRecord::query()->count())->toBe(0);
});
