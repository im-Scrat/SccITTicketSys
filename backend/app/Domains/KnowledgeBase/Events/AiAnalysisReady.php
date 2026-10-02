<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Events;

use App\Domains\Administration\Notifications\ProjectNotification;
use App\Domains\KnowledgeBase\Jobs\AnalyzeTicketJob;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Phase 2.9 — "the AI troubleshooting for your ticket is ready", sent to the
 * reporter's own private channel so their open ticket page can stop showing
 * "Analysing your report…" without polling.
 *
 * A signal, not the analysis: the payload is the ticket's uuid and nothing
 * else, and the page refetches through `GET /tickets/{ticket}/ai-analysis`,
 * which applies `viewFull`. Only the reporter is addressed — never a shared
 * channel — so another teacher cannot learn even that a ticket was analysed.
 *
 * Raised by {@see AnalyzeTicketJob} inside its transaction;
 * `ShouldDispatchAfterCommit` holds it until that commits, so a rolled-back
 * analysis announces nothing.
 */
class AiAnalysisReady implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly string $reporterUuid,
        public readonly string $ticketUuid,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("users.{$this->reporterUuid}")];
    }

    public function broadcastAs(): string
    {
        return 'ai-analysis.ready';
    }

    public function broadcastQueue(): string
    {
        return ProjectNotification::REALTIME_QUEUE;
    }

    /** @return array<string, string> */
    public function broadcastWith(): array
    {
        return ['ticket' => $this->ticketUuid];
    }
}
