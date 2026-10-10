<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Assistant\Tools;

use App\Domains\KnowledgeBase\Assistant\AssistantTool;
use App\Domains\KnowledgeBase\DTOs\KnowledgeMatch;
use App\Domains\KnowledgeBase\Exceptions\AiProviderException;
use App\Domains\KnowledgeBase\Exceptions\AiUnavailableException;
use App\Domains\KnowledgeBase\Services\AssistantContext;
use App\Domains\KnowledgeBase\Services\KnowledgeRetriever;
use App\Domains\KnowledgeBase\Services\PromptRedactor;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

/**
 * A second look at the knowledge base, for a follow-up the opening retrieval
 * did not cover. Published articles only — `KnowledgeRetriever` cannot return
 * anything else (DD-71), and the gate is `knowledge.view` like every other
 * knowledge read.
 */
final class SearchKnowledgeTool extends AssistantTool
{
    public function __construct(
        User $user,
        PromptRedactor $redactor,
        AssistantContext $context,
        private readonly KnowledgeRetriever $retriever,
    ) {
        parent::__construct($user, $redactor, $context);
    }

    public function name(): string
    {
        return 'search_knowledge';
    }

    public function description(): string
    {
        return 'Search the published IT knowledge base for troubleshooting articles about a problem.';
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->max(300)->description('What to search for.')->required(),
        ];
    }

    protected function run(Request $request): string
    {
        if (! $this->user->hasPermissionTo('knowledge.view')) {
            return 'You do not have access to the knowledge base.';
        }

        $args = $request->validate(['query' => ['required', 'string', 'max:300']]);

        try {
            $matches = $this->retriever->search(
                trim((string) $args['query']),
                (int) config('ai.sccit.assistant_top_k', 4),
                (float) config('ai.sccit.assistant_min_similarity', 0.55),
            );
        } catch (AiUnavailableException|AiProviderException) {
            return 'Knowledge search is unavailable right now.';
        }

        if ($matches === []) {
            return 'No published article matches that.';
        }

        return implode("\n\n", array_map(
            fn (KnowledgeMatch $m): string => "{$m->title}\n".$this->excerpt($m->content, 1200),
            $matches,
        ));
    }
}
