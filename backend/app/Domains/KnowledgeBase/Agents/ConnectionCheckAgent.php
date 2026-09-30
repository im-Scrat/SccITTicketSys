<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Agents;

use App\Domains\KnowledgeBase\Services\SafeAgentInvoker;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

/**
 * The smallest possible agent — used by `php artisan ai:check` to prove a real
 * chat call works end to end through the same {@see SafeAgentInvoker}
 * path production features use. It carries no school data and has no tools.
 */
#[Timeout(30)]
final class ConnectionCheckAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are a connectivity probe. Reply with the single word: ok';
    }
}
