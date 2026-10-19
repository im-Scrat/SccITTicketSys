<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Assistant\Tools;

use App\Domains\KnowledgeBase\Assistant\AssistantTool;
use App\Domains\KnowledgeBase\Services\AssistantAccountOverview;
use App\Domains\KnowledgeBase\Services\AssistantContext;
use App\Domains\KnowledgeBase\Services\PromptRedactor;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

/**
 * The same account summary the prompt already carries, refreshed — for a
 * follow-up turn whose opening block is now a few questions old.
 */
final class AccountOverviewTool extends AssistantTool
{
    public function __construct(
        User $user,
        PromptRedactor $redactor,
        AssistantContext $context,
        private readonly AssistantAccountOverview $overview,
    ) {
        parent::__construct($user, $redactor, $context);
    }

    public function name(): string
    {
        return 'account_overview';
    }

    public function description(): string
    {
        return 'A fresh summary of the current user\'s account: role, what they can do, their ticket, maintenance '
            .'and equipment numbers, and where each thing is found in the application.';
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function run(Request $request): string
    {
        return $this->overview->text($this->user);
    }
}
