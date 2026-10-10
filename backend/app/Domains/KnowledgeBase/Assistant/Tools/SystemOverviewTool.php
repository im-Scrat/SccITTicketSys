<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Assistant\Tools;

use App\Domains\Analytics\Services\DashboardService;
use App\Domains\KnowledgeBase\Assistant\AssistantTool;
use App\Domains\KnowledgeBase\Services\AssistantContext;
use App\Domains\KnowledgeBase\Services\PromptRedactor;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

/**
 * ADMINISTRATOR ONLY — the numbers on the administrator dashboard: service
 * desk, SLA, maintenance posture, estate, assets, inventory, people. Built
 * from the dashboard's own KPI items, which are counts and never names.
 */
final class SystemOverviewTool extends AssistantTool
{
    public function __construct(
        User $user,
        PromptRedactor $redactor,
        AssistantContext $context,
        private readonly DashboardService $dashboard,
    ) {
        parent::__construct($user, $redactor, $context);
    }

    public function name(): string
    {
        return 'system_overview';
    }

    public function description(): string
    {
        return 'Administrator only: system-wide numbers — open backlog, unassigned tickets, SLA breaches, tickets by '
            .'status and priority, maintenance overdue/due soon, assets, inventory low stock, buildings and rooms, accounts.';
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function run(Request $request): string
    {
        if (! $this->isAdministrator()) {
            return 'Only an administrator can see system-wide numbers.';
        }

        return AssistantDashboardText::items($this->dashboard->forUser($this->user));
    }
}
