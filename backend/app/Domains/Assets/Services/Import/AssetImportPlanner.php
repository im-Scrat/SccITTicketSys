<?php

declare(strict_types=1);

namespace App\Domains\Assets\Services\Import;

use App\Enums\AssetImportRowStatus;
use App\Enums\ImportCatalogDecision;
use App\Enums\ImportCatalogMatch;
use App\Enums\ImportEntityType;
use App\Enums\ImportLocationResolution;
use App\Models\AssetImport;
use App\Models\AssetImportCatalogEntry;
use App\Models\AssetImportRow;
use App\Models\Room;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * What confirming an import would do, in counts (Phase 2.10 R6, owner
 * decision D3).
 *
 * The confirm screen shows this plan, and the confirm request sends its
 * counts back: if anything changed in between (another administrator's
 * correction, a decision, a row that became a duplicate), the counts differ
 * and confirm is refused with 409 rather than saving a plan nobody saw.
 *
 * Only rows still to be saved count — a retried import's already-created rows
 * are history, not plan.
 */
final class AssetImportPlanner
{
    /**
     * @return array{pc_units: int, assets: int, total: int, rooms: int, without_room: int, duplicates: int, excluded: int, blocking: int, invalid: int, needs_review: int, catalog_entries: list<array{uuid: string, label: string, rows: int}>, revision: int}
     */
    public function plan(AssetImport $import): array
    {
        $rows = $import->rows()
            ->whereNull('outcome')
            ->get(['id', 'entity_type', 'status', 'is_excluded', 'location_id', 'catalog_entry_id', 'corrections']);
        $ready = $rows->where('status', AssetImportRowStatus::Ready);

        $roomIds = $this->roomIds($import, $ready);
        $placed = array_filter($roomIds, fn (?int $id): bool => $id !== null);

        $entries = $import->catalogEntries()
            ->where('decision', ImportCatalogDecision::Approved->value)
            ->where('match', '!=', ImportCatalogMatch::Existing->value)
            ->get()
            ->map(fn (AssetImportCatalogEntry $e): array => [
                'uuid' => $e->uuid,
                'label' => trim(($e->manufacturer_name ?? '').' '.$e->model_name).' · '.$e->component_type->label(),
                'rows' => $ready->where('catalog_entry_id', $e->id)->count(),
            ])
            ->filter(fn (array $e): bool => $e['rows'] > 0)
            ->values()
            ->all();

        $pc = $ready->where('entity_type', ImportEntityType::PcUnit)->count();
        $assets = $ready->where('entity_type', ImportEntityType::Asset)->count();
        $invalid = $rows->where('status', AssetImportRowStatus::Invalid)->count();
        $review = $rows->where('status', AssetImportRowStatus::NeedsReview)->count();

        return [
            'pc_units' => $pc,
            'assets' => $assets,
            'total' => $pc + $assets,
            'rooms' => count(array_unique($placed)),
            'without_room' => count($roomIds) - count($placed),
            'duplicates' => $rows->where('status', AssetImportRowStatus::Duplicate)->count(),
            'excluded' => $rows->where('status', AssetImportRowStatus::Excluded)->count(),
            'blocking' => $invalid + $review,
            'invalid' => $invalid,
            'needs_review' => $review,
            'catalog_entries' => $entries,
            'revision' => $import->revision,
        ];
    }

    /**
     * The room each row will be saved in (null: without a room), by row id.
     *
     * @param  Collection<int, AssetImportRow>  $rows
     * @return array<int, int|null>
     */
    public function roomIds(AssetImport $import, Collection $rows): array
    {
        $locations = $import->locations()->get(['id', 'resolution', 'room_id'])->keyBy('id');
        $overrides = $rows->map(fn (AssetImportRow $r): ?string => $r->corrections['room']['value'] ?? null)
            ->filter(fn (?string $v): bool => is_string($v) && Str::isUuid($v))
            ->unique()->values()->all();
        $byUuid = $overrides === [] ? collect() : Room::query()->whereIn('uuid', $overrides)->pluck('id', 'uuid');

        $out = [];
        foreach ($rows as $row) {
            if (isset($row->corrections['room'])) {
                $value = $row->corrections['room']['value'] ?? '';
                $out[$row->id] = is_string($value) && $value !== '' ? ($byUuid[$value] ?? null) : null;

                continue;
            }
            $location = $locations->get($row->location_id);
            $out[$row->id] = $location !== null && $location->resolution === ImportLocationResolution::Room ? $location->room_id : null;
        }

        return $out;
    }
}
