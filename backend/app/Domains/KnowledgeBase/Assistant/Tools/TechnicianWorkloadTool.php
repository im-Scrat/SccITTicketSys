<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Assistant\Tools;

use App\Domains\Analytics\Services\TicketMetrics;
use App\Domains\KnowledgeBase\Assistant\AssistantTool;
use App\Domains\KnowledgeBase\Services\AssistantContext;
use App\Domains\KnowledgeBase\Services\PromptRedactor;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

/**
 * ADMINISTRATOR ONLY — how the open workload is spread across technicians.
 *
 * Deliberately **without names**. The provider is a third party; which
 * technician is "Technician 2" is one click away on the Technicians page, and
 * that is where the administrator is told to look. Data minimisation
 * (FR-AI-030) beats convenience here.
 */
final class TechnicianWorkloadTool extends AssistantTool
{
    public function __construct(
        User $user,
        PromptRedactor $redactor,
        AssistantContext $context,
        private readonly TicketMetrics $tickets,
    ) {
        parent::__construct($user, $redactor, $context);
    }

    public function name(): string
    {
        return 'technician_workload';
    }

    public function description(): string
    {
        return 'Administrator only: how many open tickets each technician currently holds, busiest first. '
            .'Technicians are numbered, not named — names are on the Technicians page.';
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function run(Request $request): string
    {
        if (! $this->isAdministrator()) {
            return 'Only an administrator can see technician workload.';
        }

        $rows = $this->tickets->technicianWorkload(10);

        if ($rows === []) {
            return 'No technician currently holds an open ticket.';
        }

        $lines = ['Open tickets per technician (busiest first; names are on the Technicians page):'];

        foreach ($rows as $i => $row) {
            $lines[] = sprintf('- Technician %d: %d open', $i + 1, $row['count']);
        }

        return implode("\n", $lines);
    }
}
