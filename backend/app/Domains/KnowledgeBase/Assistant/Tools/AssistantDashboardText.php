<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Assistant\Tools;

/**
 * Renders the role dashboard's payload as plain text for the model — **KPI
 * items only**. A dashboard `row` is a table cell list that can carry a
 * person's name (technician workload, recent activity, assignments); items
 * are `label → number`. Reading only items is how the dashboard's numbers
 * reach the provider while nobody's name does.
 */
final class AssistantDashboardText
{
    /**
     * @param  array<string, mixed>  $dashboard  the `DashboardService::forUser` payload
     */
    public static function items(array $dashboard): string
    {
        $lines = [];
        $widgets = is_array($dashboard['widgets'] ?? null) ? $dashboard['widgets'] : [];

        foreach ($widgets as $widget) {
            if (! is_array($widget) || ! is_array($widget['items'] ?? null) || $widget['items'] === []) {
                continue;
            }

            $parts = [];
            foreach ($widget['items'] as $item) {
                if (! is_array($item) || ! isset($item['label'])) {
                    continue;
                }
                $value = $item['value'] ?? null;
                if (is_int($value) || is_float($value) || is_string($value)) {
                    $parts[] = "{$item['label']}: {$value}";
                }
            }

            if ($parts !== []) {
                $lines[] = (is_string($widget['title'] ?? null) ? $widget['title'] : 'Summary').' — '.implode('; ', $parts).'.';
            }
        }

        return $lines === [] ? 'No dashboard numbers are available.' : implode("\n", $lines);
    }
}
