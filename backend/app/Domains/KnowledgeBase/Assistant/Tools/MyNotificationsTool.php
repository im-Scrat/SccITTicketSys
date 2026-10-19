<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Assistant\Tools;

use App\Domains\KnowledgeBase\Assistant\AssistantTool;
use App\Models\Notification;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

/**
 * The user's own notification centre: unread count and the latest titles.
 * Addressed to a person, so the only scope is `user_id` (FR-NOT-001).
 */
final class MyNotificationsTool extends AssistantTool
{
    public function name(): string
    {
        return 'my_notifications';
    }

    public function description(): string
    {
        return 'The current user\'s notifications: how many are unread and the most recent ones with their titles and times.';
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'unread_only' => $schema->boolean()->description('List only unread notifications.'),
        ];
    }

    protected function run(Request $request): string
    {
        $args = $request->validate(['unread_only' => ['nullable', 'boolean']]);

        $own = Notification::query()->where('user_id', $this->user->getKey());
        $unread = (clone $own)->whereNull('read_at')->count();

        $rows = (clone $own)
            ->when((bool) ($args['unread_only'] ?? false), fn ($q) => $q->whereNull('read_at'))
            ->latest('id')
            ->limit(10)
            ->get();

        $lines = [sprintf('Unread notifications: %d.', $unread)];

        if ($rows->isEmpty()) {
            $lines[] = 'No notifications to show.';

            return implode("\n", $lines);
        }

        foreach ($rows as $notification) {
            $lines[] = sprintf(
                '- %s · %s%s',
                $this->date($notification->created_at),
                $notification->title,
                $notification->read_at === null ? ' (unread)' : '',
            );
        }

        return implode("\n", $lines);
    }
}
