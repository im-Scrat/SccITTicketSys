<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Services;

use App\Domains\KnowledgeBase\Assistant\AssistantTool;
use App\Domains\KnowledgeBase\Assistant\ReadOnlyAssistantTool;
use App\Domains\KnowledgeBase\Assistant\Tools\AccountOverviewTool;
use App\Domains\KnowledgeBase\Assistant\Tools\AnnouncementsTool;
use App\Domains\KnowledgeBase\Assistant\Tools\GetTicketTool;
use App\Domains\KnowledgeBase\Assistant\Tools\MyAssetsTool;
use App\Domains\KnowledgeBase\Assistant\Tools\MyNotificationsTool;
use App\Domains\KnowledgeBase\Assistant\Tools\SearchKnowledgeTool;
use App\Domains\KnowledgeBase\Assistant\Tools\SearchMaintenanceTool;
use App\Domains\KnowledgeBase\Assistant\Tools\SearchTicketsTool;
use App\Domains\KnowledgeBase\Assistant\Tools\SystemOverviewTool;
use App\Domains\KnowledgeBase\Assistant\Tools\TechnicianWorkloadTool;
use App\Models\User;
use Illuminate\Contracts\Container\Container;
use LogicException;

/**
 * Which lookups the assistant holds **for this user** (pre-commit fix pass 2).
 *
 * Every tool is constructed with the authenticated user bound in — the model
 * never names a user — and is offered only when the user's role or permission
 * would let the matching page load. The tool checks again when called; this
 * list is what the model is *told about*, not what protects the data.
 *
 * A tool that does not implement {@see ReadOnlyAssistantTool} is refused at
 * construction: the assistant advises and never acts (FR-AI-032), and that
 * must stay true however the toolbox grows.
 */
class AssistantToolbox
{
    public function __construct(private readonly Container $container) {}

    /**
     * @return list<AssistantTool>
     */
    public function for(User $user): array
    {
        $classes = [AccountOverviewTool::class, MyNotificationsTool::class, AnnouncementsTool::class, MyAssetsTool::class];

        if ($user->hasPermissionTo('tickets.view')) {
            $classes[] = SearchTicketsTool::class;
            $classes[] = GetTicketTool::class;
        }

        if ($user->hasPermissionTo('maintenance.view')) {
            $classes[] = SearchMaintenanceTool::class;
        }

        if ($user->hasPermissionTo('knowledge.view')) {
            $classes[] = SearchKnowledgeTool::class;
        }

        if ($user->isAdministrator()) {
            $classes[] = SystemOverviewTool::class;
            $classes[] = TechnicianWorkloadTool::class;
        }

        return array_map(fn (string $class): AssistantTool => $this->make($class, $user), $classes);
    }

    /** @param  class-string  $class */
    private function make(string $class, User $user): AssistantTool
    {
        $tool = $this->container->make($class, ['user' => $user]);

        // Checked on the built object, not the class name: the marker is the
        // contract, and a tool that lost it (or never had it) is refused here
        // whatever list it came from.
        if (! $tool instanceof ReadOnlyAssistantTool) {
            throw new LogicException("{$class} is not a read-only assistant tool and may not be given to the model.");
        }

        if (! $tool instanceof AssistantTool) {
            throw new LogicException("{$class} must extend AssistantTool to be bound to a user.");
        }

        return $tool;
    }
}
