<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Assistant\Tools;

use App\Domains\KnowledgeBase\Assistant\AssistantTool;
use App\Domains\KnowledgeBase\Services\AssistantContext;
use App\Domains\KnowledgeBase\Services\PromptRedactor;
use App\Domains\Maintenance\Services\MaintenanceVisibility;
use App\Enums\MaintenanceStatus;
use App\Models\MaintenanceRecord;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

/**
 * Maintenance work this user may see — `MaintenanceVisibility::scope`, the
 * same predicate as the maintenance list: their own for a technician, all of
 * it for an administrator, nothing for anyone without `maintenance.view`.
 */
final class SearchMaintenanceTool extends AssistantTool
{
    public function __construct(
        User $user,
        PromptRedactor $redactor,
        AssistantContext $context,
        private readonly MaintenanceVisibility $visibility,
    ) {
        parent::__construct($user, $redactor, $context);
    }

    public function name(): string
    {
        return 'search_maintenance';
    }

    public function description(): string
    {
        return 'Maintenance work the current user can see: scheduled, in progress, on hold or completed, with dates, '
            .'type and the machine concerned. Use it for "what maintenance do I have", "what is overdue", "what did I complete".';
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->enum(array_map(fn (MaintenanceStatus $s) => $s->value, MaintenanceStatus::cases()))->description('Only records in this status.'),
            'days' => $schema->integer()->min(1)->max(3650)->description('Only records scheduled or completed within the last/next N days.'),
            'limit' => $schema->integer()->min(1)->max(20)->description('How many to list (default 10).'),
        ];
    }

    protected function run(Request $request): string
    {
        if (! $this->user->hasPermissionTo('maintenance.view')) {
            return 'You do not have access to maintenance records.';
        }

        $args = $request->validate([
            'status' => ['nullable', 'string', 'max:30'],
            'days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $query = $this->visibility->scope(MaintenanceRecord::query(), $this->user);

        if (! empty($args['status'])) {
            $query->where('status', $args['status']);
        }

        if (! empty($args['days'])) {
            $days = (int) $args['days'];
            $query->where(fn ($q) => $q
                ->whereBetween('scheduled_for', [now()->subDays($days), now()->addDays($days)])
                ->orWhereBetween('completed_at', [now()->subDays($days), now()]));
        }

        $total = (clone $query)->count();
        $byStatus = (clone $query)->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $overdue = (clone $query)->where('status', MaintenanceStatus::Scheduled->value)->where('scheduled_for', '<', now())->count();

        $rows = (clone $query)
            ->with(['type:id,name', 'pcUnit:id,pc_name', 'asset:id,asset_tag,name', 'technician:id,first_name,middle_name,last_name'])
            ->orderByRaw('coalesce(scheduled_for, completed_at, created_at) desc')
            ->limit((int) ($args['limit'] ?? 10))
            ->get();

        $lines = [sprintf('Scope: %s — %d total; overdue %d.', $this->isAdministrator() ? 'all maintenance' : 'your maintenance', $total, $overdue)];

        if ($byStatus->isNotEmpty()) {
            $lines[] = 'By status: '.$byStatus->map(fn ($n, $status) => $this->label((string) $status)." {$n}")->implode('; ').'.';
        }

        if ($rows->isEmpty()) {
            $lines[] = 'No maintenance records match.';

            return implode("\n", $lines);
        }

        foreach ($rows as $record) {
            $this->noteName($record->technician);

            $lines[] = sprintf(
                '- %s · %s · %s · %s%s',
                $record->title,
                $this->label($record->status->value),
                $record->status === MaintenanceStatus::Completed
                    ? 'completed '.$this->date($record->completed_at)
                    : 'scheduled '.$this->date($record->scheduled_for),
                $record->type->name,
                $record->pcUnit !== null ? " · {$record->pcUnit->pc_name}" : ($record->asset !== null ? " · {$record->asset->asset_tag}" : ''),
            );
        }

        return implode("\n", $lines);
    }

    private function label(string $status): string
    {
        return ucwords(str_replace('_', ' ', $status));
    }
}
