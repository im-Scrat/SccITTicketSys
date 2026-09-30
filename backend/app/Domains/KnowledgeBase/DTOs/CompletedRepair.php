<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\DTOs;

use App\Domains\KnowledgeBase\Services\PcMaintenanceHistory;
use Carbon\CarbonImmutable;

/**
 * One completed maintenance record, as the predictive-maintenance pipeline
 * reads it (WP-L). Built only by {@see PcMaintenanceHistory}
 * from `maintenance_records` and the rows that hang off it — never from
 * `activity_logs`.
 *
 * The free-text fields (diagnosis, root cause, resolution, notes, the linked
 * ticket's title) were typed by people and are untrusted DATA wherever they go
 * next; the typed fields (dates, type, component types) are the application's
 * own facts.
 */
final readonly class CompletedRepair
{
    /**
     * @param  list<array{component_type: string, component_label: string, component_id: int|null, component_name: string|null, quantity: int, reason: string|null}>  $replacements
     * @param  list<string>  $notes
     */
    public function __construct(
        public int $id,
        public string $uuid,
        public string $typeName,
        public bool $isPreventive,
        public CarbonImmutable $completedAt,
        public ?string $diagnosis,
        public ?string $rootCause,
        public ?string $resolution,
        public ?string $preventiveRecommendation,
        public ?string $ticketCategory,
        public ?string $ticketTitle,
        public array $replacements,
        public array $notes,
    ) {}
}
