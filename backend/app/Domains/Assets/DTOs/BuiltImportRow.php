<?php

declare(strict_types=1);

namespace App\Domains\Assets\DTOs;

use App\Enums\ImportEntityType;

/**
 * One row turned into what would be saved (Phase 2.10 R6): the register it
 * joins, the payload in the shape the create rules validate, where each value
 * came from, the location and catalog text to resolve, and the row's own
 * issues.
 */
final readonly class BuiltImportRow
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $sources  payload key => file | mapping | correction | default
     * @param  array{building: string|null, floor: string|null, room: string|null, room_code: string|null, location: string|null}  $location
     * @param  array{component_type: string|null, manufacturer: string|null, model: string|null, model_number: string|null}|null  $catalog
     * @param  list<ImportIssue>  $issues
     */
    public function __construct(
        public ?ImportEntityType $type,
        public array $payload,
        public array $sources,
        public array $location,
        public ?array $catalog,
        public ?string $roomOverride,
        public ?string $supplierName,
        public ?string $custodianEmail,
        public array $issues,
    ) {}
}
