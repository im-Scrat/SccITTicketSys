<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Assistant\Tools;

use App\Domains\Administration\Services\AnnouncementVisibility;
use App\Domains\KnowledgeBase\Assistant\AssistantTool;
use App\Domains\KnowledgeBase\Services\AssistantContext;
use App\Domains\KnowledgeBase\Services\PromptRedactor;
use App\Models\Announcement;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

/** Announcements addressed to this user's role — `AnnouncementVisibility::scopeReadable` (FR-NOT-011). */
final class AnnouncementsTool extends AssistantTool
{
    public function __construct(
        User $user,
        PromptRedactor $redactor,
        AssistantContext $context,
        private readonly AnnouncementVisibility $visibility,
    ) {
        parent::__construct($user, $redactor, $context);
    }

    public function name(): string
    {
        return 'announcements';
    }

    public function description(): string
    {
        return 'Current announcements addressed to the user (pinned first): title, date and text.';
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function run(Request $request): string
    {
        $rows = $this->visibility
            ->scopeReadable(Announcement::query(), $this->user)
            ->orderByDesc('is_pinned')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(8)
            ->get();

        if ($rows->isEmpty()) {
            return 'There are no announcements for you right now.';
        }

        $lines = [sprintf('Announcements for you: %d shown.', $rows->count())];

        foreach ($rows as $announcement) {
            $lines[] = sprintf(
                '- %s%s · %s: %s',
                $announcement->is_pinned ? '[pinned] ' : '',
                $announcement->title,
                $this->date($announcement->created_at),
                $this->excerpt($announcement->content, 500),
            );
        }

        return implode("\n", $lines);
    }
}
