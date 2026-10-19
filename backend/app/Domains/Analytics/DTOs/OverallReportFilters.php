<?php

declare(strict_types=1);

namespace App\Domains\Analytics\DTOs;

use App\Enums\MaintenanceClass;

/**
 * The Overall Reports filters (Phase 2.10 R4, SDD DD-94): the shared report
 * vocabulary ({@see ReportFilters}) plus what only this page narrows by.
 *
 *  - `equipment` — one PC unit or asset (`pc:<uuid>` / `asset:<uuid>`):
 *    tickets and repairs on it, and its profile in Equipment;
 *  - `maintenanceClass`, `componentType` — apply to Repairs only;
 *  - `locationGroup` — a display choice for Locations (room, floor, building),
 *    never a filter.
 *
 * Each section says, on screen and in documents, which filters do not apply to
 * it, so a figure is never read as filtered by something it ignores.
 */
final readonly class OverallReportFilters
{
    /**
     * @param  list<string>  $maintenanceClasses
     */
    public function __construct(
        public ReportFilters $report,
        public ?string $equipmentType,
        public ?string $equipmentUuid,
        public array $maintenanceClasses,
        public ?string $componentType,
        public string $locationGroup,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(ReportFilters $report, array $validated): self
    {
        $equipment = is_string($validated['equipment'] ?? null) && str_contains($validated['equipment'], ':')
            ? explode(':', $validated['equipment'], 2)
            : [null, null];

        $classes = is_string($validated['maintenance_class'] ?? null) && $validated['maintenance_class'] !== ''
            ? array_values(array_intersect(MaintenanceClass::values(), explode(',', $validated['maintenance_class'])))
            : [];

        return new self(
            report: $report,
            equipmentType: $equipment[0],
            equipmentUuid: $equipment[1],
            maintenanceClasses: $classes,
            componentType: is_string($validated['component_type'] ?? null) && $validated['component_type'] !== '' ? $validated['component_type'] : null,
            locationGroup: in_array($validated['location_group'] ?? null, ['room', 'floor', 'building'], true) ? (string) $validated['location_group'] : 'room',
        );
    }

    public function hasEquipment(): bool
    {
        return $this->equipmentUuid !== null;
    }

    /**
     * Ticket-directory parameters that narrow to the chosen equipment.
     *
     * @return array<string, string>
     */
    public function equipmentParams(): array
    {
        return match ($this->equipmentType) {
            'pc' => ['pc_unit' => (string) $this->equipmentUuid],
            'asset' => ['asset' => (string) $this->equipmentUuid],
            default => [],
        };
    }

    /**
     * Everything that changes the numbers, for cache keys.
     *
     * @return array<string, mixed>
     */
    public function cacheKeyParts(): array
    {
        return [
            ...$this->report->cacheKeyParts(),
            'equipment' => [$this->equipmentType, $this->equipmentUuid],
            'maintenance_class' => $this->maintenanceClasses,
            'component_type' => $this->componentType,
            'location_group' => $this->locationGroup,
        ];
    }

    /**
     * The canonical query, for deep links.
     *
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        $query = [
            ...$this->report->toQuery(),
            ...array_filter([
                'equipment' => $this->equipmentUuid !== null ? $this->equipmentType.':'.$this->equipmentUuid : null,
                'maintenance_class' => $this->maintenanceClasses !== [] ? implode(',', $this->maintenanceClasses) : null,
                'component_type' => $this->componentType,
            ], static fn (?string $v): bool => $v !== null),
        ];
        ksort($query);

        return $query;
    }
}
