<?php

declare(strict_types=1);

namespace App\Domains\Assets\Services\Import;

use App\Domains\Analytics\Services\ReportsAccess;
use App\Domains\FloorPlan\Services\FloorPlanAccess;
use App\Enums\AssetImportRowOutcome;
use App\Enums\AssetImportStatus;
use App\Models\Asset;
use App\Models\AssetImport;
use App\Models\FloorPlanPosition;
use App\Models\PcUnit;
use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Where an import's records now appear (Phase 2.10 R7, SRS FR-AST-017, SDD
 * DD-99) — the slides of the post-import review.
 *
 * Built from the ledger, not from a guess: a page is a slide only if a record
 * this import created (and that still exists) appears on it, and only if the
 * viewer may open that page — each destination checks its own gate (the asset
 * policies, the room policy, `FloorPlanAccess`, `ReportsAccess`). Pages the
 * import cannot change are listed as exclusions with the reason, never as
 * empty slides. Every count shown is read the way the page itself reads it.
 *
 * Reading the review writes nothing; only acknowledging it does
 * (`AcknowledgeImportReview`).
 */
final class ImportReviewBuilder
{
    /** Instances listed per slide; above this, the filtered directory is offered. */
    public const INSTANCE_LIMIT = 50;

    public function __construct(
        private readonly FloorPlanAccess $floorPlan,
        private readonly ReportsAccess $reports,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(AssetImport $import, User $viewer): array
    {
        $rows = DB::table('asset_import_rows')
            ->where('asset_import_id', $import->getKey())
            ->where('outcome', AssetImportRowOutcome::Created->value)
            ->get(['row_number', 'created_pc_unit_id', 'created_asset_id', 'created_room_id', 'applied_fields', 'processed_at', 'payload']);

        $pcIds = $rows->pluck('created_pc_unit_id')->filter()->values()->all();
        $assetIds = $rows->pluck('created_asset_id')->filter()->values()->all();
        $applied = $rows->keyBy(fn (\stdClass $r): string => $r->created_pc_unit_id !== null ? "pc:{$r->created_pc_unit_id}" : "asset:{$r->created_asset_id}");

        $pcUnits = $pcIds === [] ? collect() : PcUnit::query()->withTrashed()->with('room.floor.building')->whereIn('id', $pcIds)->orderBy('unit_code')->get();
        $assets = $assetIds === [] ? collect() : Asset::query()->withTrashed()->with(['currentRoom.floor.building', 'hardwareModel'])->whereIn('id', $assetIds)->orderBy('asset_tag')->get();

        $slides = [];
        $exclusions = [];
        $canAssets = $viewer->can('viewAny', Asset::class);

        if ($canAssets && $pcUnits->isNotEmpty()) {
            $slides[] = $this->pcDirectory($import, $pcUnits);
            $slides[] = $this->pcDetail($pcUnits, $applied);
        }
        if ($canAssets && $assets->isNotEmpty()) {
            $slides[] = $this->assetDirectory($import, $assets);
            $slides[] = $this->assetDetail($import, $assets, $applied);
        }
        if ($viewer->can('viewAny', Room::class)) {
            $room = $this->roomDetail($pcUnits, $assets);
            if ($room !== null) {
                $slides[] = $room;
            }
            $overview = $this->locationsOverview($pcUnits);
            if ($overview !== null) {
                $slides[] = $overview;
            }
        }
        if ($this->floorPlan->canView($viewer)) {
            $plan = $this->floorPlanRoom($pcUnits);
            if ($plan !== null) {
                $slides[] = $plan;
            }
        }
        if ($canAssets && $assets->isNotEmpty()) {
            $slides[] = $this->assetDashboard($assets);
        }
        if ($this->reports->canView($viewer) && $canAssets && ($pcUnits->isNotEmpty() || $assets->isNotEmpty())) {
            $slides[] = $this->reportsEquipment($pcUnits, $assets);
        }
        $mine = $assets->where('assigned_technician_id', $viewer->getKey());
        if ($mine->isNotEmpty()) {
            $slides[] = $this->myAssets($mine);
        }

        foreach ([
            ['building_detail', 'Building details', 'Its counts are the rooms’ counts, shown on the room slides.'],
            ['floor_plan_overview', 'Floor plan overview', 'New PC units have no position yet; the room plan slide shows where to place them.'],
            ['role_dashboard', 'Your dashboard', 'It shows tickets, which an import does not create.'],
            ['tickets_maintenance', 'Tickets, maintenance and predictions', 'An import creates none of these.'],
        ] as [$kind, $label, $reason]) {
            $exclusions[] = ['kind' => $kind, 'label' => $label, 'reason' => $reason];
        }

        $created = count($pcIds) + count($assetIds);

        return [
            'import' => [
                'id' => $import->uuid,
                'file_name' => $import->original_filename,
                'outcome' => $import->outcome?->value,
                'result' => $import->result,
            ],
            'reviewable' => $created > 0 && ! in_array($import->status, [AssetImportStatus::Queued, AssetImportStatus::Importing], true),
            'receipt' => [
                'reviewed_at' => $import->reviewed_at?->toIso8601String(),
                'covers_all' => $import->reviewed_created_count !== null && $import->reviewed_created_count >= $created,
            ],
            'highlight_values' => $this->highlightValues($rows),
            'slides' => $slides,
            'exclusions' => $exclusions,
            'selectable_elsewhere' => [
                'The report-a-problem form now offers these PC units and equipment in their rooms.',
                'Maintenance scheduling can now target them.',
            ],
        ];
    }

    /**
     * @param  Collection<int, PcUnit>  $pcUnits
     * @return array<string, mixed>
     */
    private function pcDirectory(AssetImport $import, Collection $pcUnits): array
    {
        $live = $pcUnits->whereNull('deleted_at')->count();

        return $this->slide('pc_unit_directory', 'PC unit directory', 'Assets › PC units',
            "The {$this->n($live, 'PC unit')} this import created, shown on their own. Remove the filter on the page to see them among the rest.",
            "/app/assets/list?tab=pc-units&import={$import->uuid}");
    }

    /**
     * @param  Collection<int, PcUnit>  $pcUnits
     * @param  Collection<string, \stdClass>  $applied
     * @return array<string, mixed>
     */
    private function pcDetail(Collection $pcUnits, Collection $applied): array
    {
        $fields = $pcUnits->flatMap(fn (PcUnit $pc): array => (array) json_decode((string) ($applied->get("pc:{$pc->id}")->applied_fields ?? '[]'), true))->unique();
        $views = [['key' => 'general', 'label' => 'General']];
        if ($fields->contains(fn (string $f): bool => str_starts_with($f, 'specification.'))) {
            $specs = $fields->filter(fn (string $f): bool => str_starts_with($f, 'specification.'))->count();
            $views[] = ['key' => 'specifications', 'label' => "Specifications ({$specs})"];
        }
        if ($fields->contains('purchase_date') || $fields->contains('warranty_expiration')) {
            $views[] = ['key' => 'warranty', 'label' => 'Warranty'];
        }

        $instances = $pcUnits->take(self::INSTANCE_LIMIT)->map(fn (PcUnit $pc): array => [
            'id' => $pc->uuid,
            'label' => "{$pc->unit_code} · {$pc->pc_name}",
            'path' => "/app/assets/pc-units/{$pc->uuid}",
            'hint' => $pc->room !== null ? ImportLocationMatcher::roomLabel($pc->room) : 'No room',
            'archived' => $pc->trashed(),
            'edited' => $this->edited($pc->updated_at, $applied->get("pc:{$pc->id}")->processed_at ?? null),
        ])->values()->all();

        return [
            ...$this->slide('pc_unit_detail', 'PC unit details', 'Assets › PC unit',
                'Each PC unit’s own page, with the values from your file marked.',
                $instances[0]['path'] ?? '/app/assets/list?tab=pc-units'),
            'views' => $views,
            'instances' => $instances,
            'instance_total' => $pcUnits->count(),
        ];
    }

    /**
     * @param  Collection<int, Asset>  $assets
     * @return array<string, mixed>
     */
    private function assetDirectory(AssetImport $import, Collection $assets): array
    {
        return $this->slide('asset_directory', 'Asset directory', 'Assets › Equipment',
            "The {$this->n($assets->whereNull('deleted_at')->count(), 'asset')} this import created, shown on their own.",
            "/app/assets/list?import={$import->uuid}");
    }

    /**
     * @param  Collection<int, Asset>  $assets
     * @param  Collection<string, \stdClass>  $applied
     * @return array<string, mixed>
     */
    private function assetDetail(AssetImport $import, Collection $assets, Collection $applied): array
    {
        $fields = $assets->flatMap(fn (Asset $a): array => (array) json_decode((string) ($applied->get("asset:{$a->id}")->applied_fields ?? '[]'), true))->unique();
        $views = [['key' => 'general', 'label' => 'General']];
        if ($fields->contains('purchase_date') || $fields->contains('warranty_expiration')) {
            $views[] = ['key' => 'warranty', 'label' => 'Warranty'];
        }
        if ($import->catalogEntries()->whereNotNull('created_model_id')->exists()) {
            $views[] = ['key' => 'specifications', 'label' => 'Specifications'];
        }

        $instances = $assets->take(self::INSTANCE_LIMIT)->map(fn (Asset $asset): array => [
            'id' => $asset->uuid,
            'label' => trim($asset->asset_tag.' · '.($asset->name ?? $asset->hardwareModel->model_name ?? ''), ' ·'),
            'path' => "/app/assets/{$asset->uuid}",
            'hint' => $asset->currentRoom !== null ? ImportLocationMatcher::roomLabel($asset->currentRoom) : 'No room',
            'archived' => $asset->trashed(),
            'edited' => $this->edited($asset->updated_at, $applied->get("asset:{$asset->id}")->processed_at ?? null),
        ])->values()->all();

        return [
            ...$this->slide('asset_detail', 'Asset details', 'Assets › Asset',
                'Each asset’s own page, with the values from your file marked.',
                $instances[0]['path'] ?? '/app/assets/list'),
            'views' => $views,
            'instances' => $instances,
            'instance_total' => $assets->count(),
        ];
    }

    /**
     * @param  Collection<int, PcUnit>  $pcUnits
     * @param  Collection<int, Asset>  $assets
     * @return array<string, mixed>|null
     */
    private function roomDetail(Collection $pcUnits, Collection $assets): ?array
    {
        $byRoom = [];
        foreach ($pcUnits->whereNull('deleted_at') as $pc) {
            if ($pc->room !== null && ! $pc->room->trashed()) {
                $byRoom[$pc->room->id]['room'] = $pc->room;
                $byRoom[$pc->room->id]['pc'] = ($byRoom[$pc->room->id]['pc'] ?? 0) + 1;
            }
        }
        foreach ($assets->whereNull('deleted_at') as $asset) {
            if ($asset->currentRoom !== null && ! $asset->currentRoom->trashed()) {
                $byRoom[$asset->currentRoom->id]['room'] = $asset->currentRoom;
                $byRoom[$asset->currentRoom->id]['asset'] = ($byRoom[$asset->currentRoom->id]['asset'] ?? 0) + 1;
            }
        }
        if ($byRoom === []) {
            return null;
        }

        uasort($byRoom, fn (array $a, array $b): int => (($b['pc'] ?? 0) + ($b['asset'] ?? 0)) <=> (($a['pc'] ?? 0) + ($a['asset'] ?? 0)));
        $instances = array_values(array_map(fn (array $entry): array => [
            'id' => $entry['room']->uuid,
            'label' => ImportLocationMatcher::roomLabel($entry['room']),
            'path' => "/app/locations/rooms/{$entry['room']->uuid}",
            'hint' => implode(' · ', array_filter([
                isset($entry['pc']) ? '+'.$this->n($entry['pc'], 'PC unit') : null,
                isset($entry['asset']) ? '+'.$this->n($entry['asset'], 'asset') : null,
            ])).' from this import',
            'archived' => false,
            'edited' => false,
        ], array_slice($byRoom, 0, self::INSTANCE_LIMIT, true)));

        return [
            ...$this->slide('room_detail', 'Room details', 'Locations › Room',
                'The rooms the imported records were placed in. Their PC unit and asset counts now include them.',
                $instances[0]['path']),
            'instances' => $instances,
            'instance_total' => count($byRoom),
        ];
    }

    /**
     * @param  Collection<int, PcUnit>  $pcUnits
     * @return array<string, mixed>|null
     */
    private function locationsOverview(Collection $pcUnits): ?array
    {
        $live = $pcUnits->whereNull('deleted_at');
        $rooms = $live->pluck('room_id')->filter()->unique()->count();
        $unplaced = $live->whereNull('room_id')->count();
        if ($rooms < 2 && $unplaced === 0) {
            return null;
        }

        $parts = array_filter([
            $rooms >= 2 ? "PC units landed in {$rooms} rooms" : null,
            $unplaced > 0 ? $this->n($unplaced, 'PC unit').' without a room now count as unplaced' : null,
        ]);

        return $this->slide('locations_overview', 'Locations overview', 'Locations',
            ucfirst(implode('; ', $parts)).'.', '/app/locations');
    }

    /**
     * Rooms with an active floor plan that received imported PC units, counted
     * with the plan's own "unplaced" rule: in the room, not on its active layout.
     *
     * @param  Collection<int, PcUnit>  $pcUnits
     * @return array<string, mixed>|null
     */
    private function floorPlanRoom(Collection $pcUnits): ?array
    {
        $instances = [];
        foreach ($pcUnits->whereNull('deleted_at')->whereNotNull('room_id')->groupBy('room_id') as $units) {
            $room = $units->first()->room;
            if ($room === null || $room->trashed()) {
                continue;
            }
            $layout = $room->layouts()->where('is_active', true)->first();
            if ($layout === null) {
                continue;
            }
            $placed = FloorPlanPosition::query()->where('room_layout_id', $layout->getKey())->whereIn('pc_unit_id', $units->pluck('id'))->count();
            $notYet = $units->count() - $placed;
            $instances[] = [
                'id' => $room->uuid,
                'label' => ImportLocationMatcher::roomLabel($room),
                'path' => "/app/floor-plan/rooms/{$room->uuid}",
                'hint' => $this->n($notYet, 'PC unit').' not on the plan yet'.($placed > 0 ? ", {$placed} placed since the import" : ''),
                'archived' => false,
                'edited' => false,
            ];
        }
        if ($instances === []) {
            return null;
        }

        return [
            ...$this->slide('floor_plan_room', 'Floor plan', 'Floor plan › Room',
                'New PC units appear in the room’s list of units not yet placed; drag them onto the plan when you are ready.',
                $instances[0]['path']),
            'instances' => array_slice($instances, 0, self::INSTANCE_LIMIT),
            'instance_total' => count($instances),
        ];
    }

    /**
     * @param  Collection<int, Asset>  $assets
     * @return array<string, mixed>
     */
    private function assetDashboard(Collection $assets): array
    {
        return $this->slide('asset_dashboard', 'Asset dashboard', 'Assets',
            "Its totals and “Recently added” now include the {$this->n($assets->whereNull('deleted_at')->count(), 'asset')} from this import.",
            '/app/assets');
    }

    /**
     * @param  Collection<int, PcUnit>  $pcUnits
     * @param  Collection<int, Asset>  $assets
     * @return array<string, mixed>
     */
    private function reportsEquipment(Collection $pcUnits, Collection $assets): array
    {
        $parts = array_filter([
            $pcUnits->isNotEmpty() ? $this->n($pcUnits->whereNull('deleted_at')->count(), 'more PC unit', 'more PC units') : null,
            $assets->isNotEmpty() ? $this->n($assets->whereNull('deleted_at')->count(), 'more asset', 'more assets') : null,
        ]);

        return $this->slide('reports_equipment', 'Overall reports — Equipment', 'Overall reports › Equipment',
            'The inventory now counts '.implode(' and ', $parts).'.',
            '/app/reports?section=equipment');
    }

    /**
     * @param  Collection<int, Asset>  $mine
     * @return array<string, mixed>
     */
    private function myAssets(Collection $mine): array
    {
        return $this->slide('my_assets', 'My assets', 'My assets',
            "You are the custodian of {$this->n($mine->count(), 'asset')} from this import.",
            '/app/my-assets');
    }

    /**
     * @return array{kind: string, title: string, page_label: string, explanation: string, path: string, views: list<array<string, string>>, instances: list<array<string, mixed>>, instance_total: int}
     */
    private function slide(string $kind, string $title, string $pageLabel, string $explanation, string $path): array
    {
        return [
            'kind' => $kind,
            'title' => $title,
            'page_label' => $pageLabel,
            'explanation' => $explanation,
            'path' => $path,
            'views' => [],
            'instances' => [],
            'instance_total' => 0,
        ];
    }

    /**
     * The values the file supplied, for the preview to mark.
     *
     * @param  Collection<int, \stdClass>  $rows
     * @return list<string>
     */
    private function highlightValues(Collection $rows): array
    {
        $values = [];
        foreach ($rows as $row) {
            $payload = (array) json_decode((string) $row->payload, true);
            foreach ((array) json_decode((string) $row->applied_fields, true) as $field) {
                $value = str_starts_with($field, 'specification.')
                    ? ($payload['specification'][substr($field, 14)] ?? null)
                    : ($payload[$field] ?? null);
                if (is_string($value) && mb_strlen(trim($value)) >= 3 && ! in_array($field, ['status', 'current_condition', 'condition', 'room'], true)) {
                    $values[trim($value)] = true;
                }
            }
            if (count($values) >= 300) {
                break;
            }
        }

        return array_keys($values);
    }

    private function edited(mixed $updatedAt, mixed $processedAt): bool
    {
        return $updatedAt !== null && $processedAt !== null && strtotime((string) $updatedAt) > strtotime((string) $processedAt) + 1;
    }

    private function n(int $count, string $one, ?string $many = null): string
    {
        return $count === 1 ? "1 {$one}" : "{$count} ".($many ?? "{$one}s");
    }
}
