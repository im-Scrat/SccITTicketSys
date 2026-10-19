<?php

declare(strict_types=1);

namespace App\Domains\Assets\Services\Import;

use App\Domains\Assets\DTOs\BuiltImportRow;
use App\Domains\Assets\DTOs\ImportIssue;
use App\Enums\AssetImportRowStatus;
use App\Enums\ImportCatalogDecision;
use App\Enums\ImportCatalogMatch;
use App\Enums\ImportEntityType;
use App\Enums\ImportIssueSeverity;
use App\Enums\ImportLocationMatch;
use App\Enums\ImportLocationResolution;
use App\Models\AssetImport;
use App\Models\AssetImportCatalogEntry;
use App\Models\AssetImportLocation;
use App\Models\AssetImportRow;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Checks an import's rows and keeps every derived fact consistent (Phase 2.10
 * R6, SDD DD-96).
 *
 * Three passes, each owning its own issues (by `scope`):
 *
 *  - **row** — what one row says on its own: built from its cells, mapping and
 *    corrections; the shared create rules; collisions with the register; its
 *    supplier and custodian. Rebuilt only for the rows that changed.
 *  - **link** — whether its location and catalog decisions are made.
 *  - **file** — whether it repeats another row.
 *
 * The link and file passes and the status of every row are recomputed over the
 * whole import after any change, so a correction to one row that resolves a
 * clash with another updates both — there is no state a client has to
 * remember to refresh. Row status is derived from the issues that remain once
 * the administrator's acknowledgements are taken out, in the order
 * error → duplicate → review → ready; an exclusion wins over all of them.
 */
final class AssetImportValidation
{
    public function __construct(
        private readonly ImportRowBuilder $builder,
        private readonly ImportRowValidator $validator,
    ) {}

    /** Check every row (after a mapping change or a re-read). */
    public function validateAll(AssetImport $import): void
    {
        $this->process($import, $import->rows()->orderBy('row_number')->get(), full: true);
    }

    /**
     * Rebuild these rows (after a correction), then refresh everything derived.
     *
     * @param  Collection<int, AssetImportRow>  $rows
     */
    public function revalidateRows(AssetImport $import, Collection $rows): void
    {
        $this->process($import, $rows, full: false);
    }

    /** Refresh link and file issues and statuses (after a decision or an exclusion). */
    public function refresh(AssetImport $import): void
    {
        DB::transaction(fn () => $this->finalPass($import));
    }

    /**
     * @param  Collection<int, AssetImportRow>  $rows
     */
    private function process(AssetImport $import, Collection $rows, bool $full): void
    {
        $sheet = $import->source_meta['sheet'] ?? null;
        $quantityColumn = $import->source_meta['quantity_column'] ?? null;

        /** @var array<int, BuiltImportRow> $built */
        $built = [];
        foreach ($rows as $row) {
            $built[$row->id] = $this->builder->build($import, $row, is_string($sheet) ? $sheet : null);
        }

        $duplicates = new ImportDuplicateDetector;
        $duplicates->prepare(array_values(array_map(
            fn (BuiltImportRow $b): array => [$b->type ?? ImportEntityType::PcUnit, $b->payload],
            array_filter($built, fn (BuiltImportRow $b): bool => $b->type !== null),
        )));
        $references = new ImportReferenceResolver;

        DB::transaction(function () use ($import, $rows, $built, $duplicates, $references, $full, $quantityColumn): void {
            $locations = $this->syncLocations($import, $built, $full);
            $entries = $this->syncCatalog($import, $built, $full);

            foreach ($rows as $row) {
                $b = $built[$row->id];
                $issues = $b->issues;
                $payload = $b->payload;
                $sources = $b->sources;

                if ($b->type !== null) {
                    $issues = [...$issues, ...$this->validator->validate($b->type, $payload)];
                    $issues = [...$issues, ...$duplicates->existing($b->type, $payload)];

                    if ($b->type === ImportEntityType::Asset) {
                        $resolved = $references->resolve($b->supplierName, $b->custodianEmail);
                        $payload = [...$payload, ...$resolved['payload']];
                        foreach (['supplier' => 'supplier', 'technician' => 'custodian_email'] as $key => $target) {
                            if (isset($resolved['payload'][$key])) {
                                $sources[$key] = $this->sourceOf($row, $target);
                            }
                        }
                        $issues = [...$issues, ...$resolved['issues']];
                    }
                }

                if (is_int($quantityColumn) || is_string($quantityColumn)) {
                    $quantity = trim((string) ($row->raw[(string) $quantityColumn] ?? ''));
                    if (is_numeric($quantity) && (float) $quantity > 1) {
                        $issues[] = ImportIssue::make('quantity_gt_one', null, "The quantity is {$quantity}, but one row creates one record. Split it into one row per item, or acknowledge to create just one.");
                    }
                }

                $row->entity_type = $b->type;
                $row->payload = $payload;
                $row->sources = $sources;
                $row->location_id = $locations[ImportLocationMatcher::normalizedKey($b->location)]->id ?? null;
                $key = $b->catalog !== null ? ImportCatalogResolver::normalizedKey($b->catalog) : null;
                $row->catalog_entry_id = $key !== null ? ($entries[$key]->id ?? null) : null;
                $row->search_text = mb_substr(mb_strtolower(implode(' ', array_filter([
                    $payload['unit_code'] ?? null, $payload['asset_tag'] ?? null, $payload['pc_name'] ?? null, $payload['name'] ?? null,
                    $payload['serial_number'] ?? null, $payload['hostname'] ?? null, ImportLocationMatcher::sourceText($b->location),
                    $b->catalog['model'] ?? null,
                ], fn ($v): bool => is_string($v) && $v !== ''))), 0, 1024);
                $row->issues = [
                    ...array_values(array_filter($row->issues ?? [], fn (array $i): bool => ($i['scope'] ?? ImportIssue::SCOPE_ROW) !== ImportIssue::SCOPE_ROW)),
                    ...array_map(fn (ImportIssue $i): array => $i->toArray(), $this->unique($issues)),
                ];
                $row->save();
            }

            $this->finalPass($import);
        });
    }

    /**
     * Link and file issues, row statuses and counts, over the whole import.
     */
    private function finalPass(AssetImport $import): void
    {
        $rows = $import->rows()
            ->orderBy('row_number')
            ->get(['id', 'asset_import_id', 'row_number', 'entity_type', 'payload', 'corrections', 'acknowledged', 'issues', 'status', 'is_excluded', 'location_id', 'catalog_entry_id']);
        $locations = $import->locations()->get()->keyBy('id');
        $entries = $import->catalogEntries()->get()->keyBy('id');

        $candidates = [];
        foreach ($rows as $row) {
            if (! $row->is_excluded && $row->entity_type !== null && $row->payload !== null) {
                $candidates[$row->row_number] = [$row->entity_type, $row->payload];
            }
        }
        $fileIssues = (new ImportDuplicateDetector)->withinFile($candidates);

        foreach ($rows as $row) {
            $kept = array_values(array_filter($row->issues ?? [], fn (array $i): bool => ($i['scope'] ?? ImportIssue::SCOPE_ROW) === ImportIssue::SCOPE_ROW));
            $link = $this->linkIssues($row, $locations->get($row->location_id), $entries->get($row->catalog_entry_id));
            $file = $fileIssues[$row->row_number] ?? [];

            $row->issues = [...$kept, ...array_map(fn (ImportIssue $i): array => $i->toArray(), [...$link, ...$file])];
            $row->status = self::statusFor($row->issues, $row->acknowledged ?? [], $row->is_excluded);
            if ($row->isDirty()) {
                $row->save();
            }
        }

        foreach ($locations as $location) {
            $count = $rows->where('location_id', $location->id)->count();
            if ($count === 0) {
                $location->delete();
            } elseif ($location->row_count !== $count) {
                $location->update(['row_count' => $count]);
            }
        }
        foreach ($entries as $entry) {
            $count = $rows->where('catalog_entry_id', $entry->id)->count();
            if ($count === 0) {
                $entry->delete();
            } elseif ($entry->row_count !== $count) {
                $entry->update(['row_count' => $count]);
            }
        }

        $counts = array_fill_keys(AssetImportRowStatus::values(), 0);
        foreach ($rows as $row) {
            $counts[$row->status->value]++;
        }
        $import->forceFill(['row_counts' => $counts, 'total_rows' => $rows->count()])->save();
    }

    /**
     * A row's status from its issues.
     *
     * @param  list<array<string, mixed>>  $issues
     * @param  list<string>  $acknowledged
     */
    public static function statusFor(array $issues, array $acknowledged, bool $excluded): AssetImportRowStatus
    {
        if ($excluded) {
            return AssetImportRowStatus::Excluded;
        }

        $severities = [];
        foreach ($issues as $issue) {
            if (ImportIssue::isAcknowledgeable((string) $issue['code']) && in_array($issue['code'], $acknowledged, true)) {
                continue;
            }
            $severities[(string) $issue['severity']] = true;
        }

        return match (true) {
            isset($severities[ImportIssueSeverity::Error->value]) => AssetImportRowStatus::Invalid,
            isset($severities[ImportIssueSeverity::Duplicate->value]) => AssetImportRowStatus::Duplicate,
            isset($severities[ImportIssueSeverity::Review->value]) => AssetImportRowStatus::NeedsReview,
            default => AssetImportRowStatus::Ready,
        };
    }

    /**
     * @return list<ImportIssue>
     */
    private function linkIssues(AssetImportRow $row, ?AssetImportLocation $location, ?AssetImportCatalogEntry $entry): array
    {
        if ($row->entity_type === null) {
            return [];
        }

        $issues = [];
        $override = isset($row->corrections['room']);

        if (! $override && $location !== null && $location->resolution === ImportLocationResolution::Pending) {
            $issues[] = match (true) {
                $location->normalized === '' => ImportIssue::make('location_blank', 'location', 'This row has no location. Choose a room, or leave it without one, in Locations.', [], ImportIssue::SCOPE_LINK),
                $location->match === ImportLocationMatch::NotSelectable => ImportIssue::make('location_inactive', 'location', 'The matching room is not in use. Assign it anyway, or choose another, in Locations.', ['location' => $location->uuid], ImportIssue::SCOPE_LINK),
                default => ImportIssue::make('location_unresolved', 'location', '“'.$location->source_text.'” needs a room. Choose one in Locations.', ['location' => $location->uuid], ImportIssue::SCOPE_LINK),
            };
        }

        if ($row->entity_type === ImportEntityType::Asset && $entry !== null) {
            $label = trim(($entry->manufacturer_name ?? '').' '.$entry->model_name);
            $issue = match ($entry->decision) {
                ImportCatalogDecision::Pending => in_array($entry->match, [ImportCatalogMatch::Suggested, ImportCatalogMatch::Blocked], true)
                    ? ImportIssue::make('catalog_ambiguous', 'model', "{$label} may already be in the catalog. Decide in Catalog.", ['entry' => $entry->uuid], ImportIssue::SCOPE_LINK)
                    : ImportIssue::make('catalog_pending', 'model', "{$label} is not in the catalog yet. Approve it, or choose an existing model, in Catalog.", ['entry' => $entry->uuid], ImportIssue::SCOPE_LINK),
                ImportCatalogDecision::Rejected => ImportIssue::make('catalog_rejected', 'model', "You chose not to import rows for {$label}.", ['entry' => $entry->uuid], ImportIssue::SCOPE_LINK),
                default => null,
            };
            if ($issue !== null) {
                $issues[] = $issue;
            }
        }

        return $issues;
    }

    /**
     * Create or refresh one location decision per distinct location text.
     * A decision the administrator made is kept; an automatic one follows the
     * estate as it is now.
     *
     * @param  array<int, BuiltImportRow>  $built
     * @return array<string, AssetImportLocation>
     */
    private function syncLocations(AssetImport $import, array $built, bool $full): array
    {
        $matcher = new ImportLocationMatcher;
        $existing = $import->locations()->get()->keyBy('normalized');
        $out = [];

        foreach ($built as $b) {
            if ($b->type === null) {
                continue;
            }
            $key = ImportLocationMatcher::normalizedKey($b->location);
            if (isset($out[$key])) {
                continue;
            }

            $location = $existing->get($key);
            if ($location !== null && ! $full) {
                $out[$key] = $location;

                continue;
            }

            $match = $key === '' ? ['match' => ImportLocationMatch::Unmatched, 'room_id' => null, 'candidates' => []] : $matcher->match($b->location);
            $attributes = [
                'source_text' => ImportLocationMatcher::sourceText($b->location),
                'parts' => $b->location,
                'match' => $match['match'],
                'candidates' => $match['candidates'],
            ];

            if ($location === null) {
                $location = $import->locations()->create([
                    'normalized' => $key,
                    ...$attributes,
                    ...$this->autoResolution($match),
                ]);
            } else {
                $location->fill($attributes);
                if ($location->decided_by === null) {
                    $location->fill($this->autoResolution($match));
                }
                $location->save();
            }
            $out[$key] = $location;
        }

        return $out;
    }

    /**
     * @param  array{match: ImportLocationMatch, room_id: int|null}  $match
     * @return array{resolution: ImportLocationResolution, room_id: int|null}
     */
    private function autoResolution(array $match): array
    {
        return $match['match'] === ImportLocationMatch::Matched
            ? ['resolution' => ImportLocationResolution::Room, 'room_id' => $match['room_id']]
            : ['resolution' => ImportLocationResolution::Pending, 'room_id' => null];
    }

    /**
     * Create or refresh one catalog proposal per distinct combination. A
     * combination already in the catalog is approved automatically; anything
     * else waits for the administrator.
     *
     * @param  array<int, BuiltImportRow>  $built
     * @return array<string, AssetImportCatalogEntry>
     */
    private function syncCatalog(AssetImport $import, array $built, bool $full): array
    {
        $resolver = new ImportCatalogResolver;
        $existing = $import->catalogEntries()->get()->keyBy('normalized');
        $out = [];

        foreach ($built as $b) {
            if ($b->type !== ImportEntityType::Asset || $b->catalog === null) {
                continue;
            }
            $key = ImportCatalogResolver::normalizedKey($b->catalog);
            if ($key === null || isset($out[$key])) {
                continue;
            }

            $entry = $existing->get($key);
            if ($entry !== null && ! $full) {
                $out[$key] = $entry;

                continue;
            }

            $match = $resolver->match($b->catalog);
            $attributes = [
                'component_type' => $b->catalog['component_type'],
                'match' => $match['match'],
                'matched_model_id' => $match['model_id'],
                'suggestions' => $match['suggestions'],
            ];
            $auto = $match['match'] === ImportCatalogMatch::Existing
                ? ['decision' => ImportCatalogDecision::Approved]
                : ['decision' => ImportCatalogDecision::Pending];

            if ($entry === null) {
                $entry = $import->catalogEntries()->create([
                    'normalized' => $key,
                    'manufacturer_name' => $b->catalog['manufacturer'] !== null ? trim($b->catalog['manufacturer']) : null,
                    'model_name' => trim((string) $b->catalog['model']),
                    'model_number' => $b->catalog['model_number'] !== null ? trim($b->catalog['model_number']) : null,
                    ...$attributes,
                    ...$auto,
                ]);
            } else {
                $entry->fill($attributes);
                if ($entry->decided_by === null) {
                    $entry->fill($auto);
                }
                $entry->save();
            }
            $out[$key] = $entry;
        }

        return $out;
    }

    private function sourceOf(AssetImportRow $row, string $target): string
    {
        return isset($row->corrections[$target]) ? 'correction' : 'file';
    }

    /**
     * One issue per code and field.
     *
     * @param  list<ImportIssue>  $issues
     * @return list<ImportIssue>
     */
    private function unique(array $issues): array
    {
        $seen = [];
        $out = [];
        foreach ($issues as $issue) {
            $key = $issue->code.'|'.$issue->field;
            // The builder's "required" and the rule's "required" are one problem.
            $alias = $issue->code === 'required_missing' ? 'required|'.$issue->field : $key;
            if (isset($seen[$key]) || isset($seen[$alias])) {
                continue;
            }
            $seen[$key] = $seen[$alias] = true;
            $out[] = $issue;
        }

        return $out;
    }
}
