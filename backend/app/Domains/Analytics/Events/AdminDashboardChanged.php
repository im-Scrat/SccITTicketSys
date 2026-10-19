<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Events;

use App\Domains\Administration\Notifications\ProjectNotification;
use App\Domains\Analytics\Services\AdminDashboardSignal;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Phase 2.9 — "the operational picture changed", on the administrators'
 * channel. Raised only through {@see AdminDashboardSignal}.
 *
 * A thin signal by design — `kind` (e.g. `ticket.created`) and the subject's
 * uuid, nothing else. The admin dashboard answers it by refetching through its
 * normal, authorized endpoints; the socket never carries a title, a name or a
 * figure. `ShouldDispatchAfterCommit`: a change that rolls back is never
 * announced.
 */
class AdminDashboardChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    public const CHANNEL = 'admin.dashboard';

    public function __construct(
        public readonly string $kind,
        public readonly ?string $subject = null,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(self::CHANNEL)];
    }

    public function broadcastAs(): string
    {
        return 'dashboard.changed';
    }

    public function broadcastQueue(): string
    {
        return ProjectNotification::REALTIME_QUEUE;
    }

    /** @return array{kind: string, subject: string|null} */
    public function broadcastWith(): array
    {
        return ['kind' => $this->kind, 'subject' => $this->subject];
    }
}
