<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domains\KnowledgeBase\Services\SafeAgentInvoker;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

/**
 * A minimal concrete agent for exercising {@see SafeAgentInvoker}
 * against `laravel/ai`'s own real fake gateway (`FakeTestAgent::fake([...])`) rather than a
 * hand-rolled mock — the WP-H mandate calls for "deterministic provider mocks/fakes", and
 * the SDK ships exactly that.
 */
final class FakeTestAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are a test agent used only in the Pest suite. Never called against a real provider.';
    }
}
