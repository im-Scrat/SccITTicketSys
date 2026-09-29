<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domains\KnowledgeBase\Services\SafeAgentInvoker;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * Same purpose as {@see FakeTestAgent}, declaring a structured-output schema
 * so tests can exercise {@see SafeAgentInvoker}'s
 * malformed/empty structured-output detection.
 */
final class FakeStructuredTestAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are a test agent used only in the Pest suite. Respond with the requested JSON shape.';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'category' => $schema->string(),
        ];
    }
}
