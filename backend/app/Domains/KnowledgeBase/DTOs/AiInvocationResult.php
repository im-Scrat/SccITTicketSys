<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\DTOs;

use App\Domains\KnowledgeBase\Services\SafeAgentInvoker;

/**
 * What {@see SafeAgentInvoker} hands back
 * for one successful agent call.
 *
 * Deliberately plain and feature-agnostic — WP-H owns no `ai_*` table of its
 * own (`ai_analysis_logs` is ticket-scoped, `ai_conversation_logs` is
 * conversation-scoped; both belong to the features that will write to them).
 * A caller maps these fields onto whichever row it persists.
 */
final readonly class AiInvocationResult
{
    /**
     * @param  array<string, mixed>|null  $structured  Present only when the agent implements HasStructuredOutput.
     */
    public function __construct(
        public string $text,
        public ?array $structured,
        public string $provider,
        public string $model,
        public int $promptTokens,
        public int $completionTokens,
        public int $latencyMs,
    ) {}
}
