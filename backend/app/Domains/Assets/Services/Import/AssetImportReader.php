<?php

declare(strict_types=1);

namespace App\Domains\Assets\Services\Import;

use App\Domains\Assets\Exceptions\ImportFileRejected;
use App\Domains\Assets\Jobs\ValidateAssetImportJob;
use App\Enums\AssetImportRowStatus;
use App\Enums\AssetImportStatus;
use App\Models\AssetImport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Turns an import's stored file into ledger rows and a proposed mapping
 * (Phase 2.10 R6).
 *
 * Reading again (another sheet, another header row, another encoding) replaces
 * the rows and every decision made on them: they described a different table.
 * A file whose columns are exactly the template's goes straight on to the
 * row check, so a template upload with no problems lands on Confirm.
 */
final class AssetImportReader
{
    public function __construct(
        private readonly SpreadsheetReader $reader,
        private readonly ImportColumnMapper $mapper,
        private readonly AssetImportValues $values,
    ) {}

    public function read(AssetImport $import): void
    {
        try {
            if (! $import->hasFile()) {
                throw ImportFileRejected::unreadable();
            }
            $path = Storage::disk($import->disk)->path((string) $import->path);
            $result = $this->reader->read($path, $import->extension, $import->entity_mode, $import->source_options ?? []);
        } catch (ImportFileRejected $e) {
            $import->forceFill([
                'status' => AssetImportStatus::Unreadable,
                'error_code' => $e->reason,
                'source_meta' => [...($import->source_meta ?? []), 'error' => $e->getMessage()],
            ])->save();

            return;
        }

        $suggestion = $this->mapper->suggest($result['headers'], $import->entity_mode);
        $mapping = $this->mapper->asMapping($suggestion['columns']);
        $quantity = collect($suggestion['columns'])->firstWhere('quantity', true);

        DB::transaction(function () use ($import, $result, $suggestion, $mapping, $quantity): void {
            $import->rows()->delete();
            $import->locations()->delete();
            $import->catalogEntries()->delete();

            $now = now();
            foreach (array_chunk($result['rows'], 500) as $chunk) {
                DB::table('asset_import_rows')->insert(array_map(fn (array $row): array => [
                    'asset_import_id' => $import->getKey(),
                    'row_number' => $row['row_number'],
                    'raw' => json_encode((object) $row['cells'], JSON_UNESCAPED_UNICODE),
                    'cell_flags' => $row['flags'] === [] ? null : json_encode((object) $row['flags']),
                    'status' => AssetImportRowStatus::NeedsReview->value,
                    'is_excluded' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk));
            }

            $import->forceFill([
                'headers' => $suggestion['columns'],
                'mapping' => $mapping,
                'value_mappings' => [],
                'date_order' => null,
                'source_meta' => [
                    ...$result['meta'],
                    'template_match' => $suggestion['template_match'],
                    'quantity_column' => $quantity['index'] ?? null,
                ],
                'total_rows' => count($result['rows']),
                'row_counts' => null,
                'error_code' => null,
                'status' => AssetImportStatus::Mapping,
                'revision' => $import->revision + 1,
            ])->save();

            $import->forceFill(['value_mappings' => $this->values->suggestedMeanings($import, $mapping)])->save();
        });

        // The template fast path: every column is the template's, every value
        // has a meaning and no date needs an order — check the rows now.
        if ($suggestion['template_match'] && ! $this->values->dateSummary($import, $mapping)['required'] && $this->allValuesKnown($import, $mapping)) {
            $import->forceFill(['status' => AssetImportStatus::Validating])->save();
            ValidateAssetImportJob::dispatch($import->getKey(), $import->revision);
        }
    }

    /**
     * @param  array<string, string>  $mapping
     */
    private function allValuesKnown(AssetImport $import, array $mapping): bool
    {
        foreach ($this->values->meaningTables($import, $mapping) as $table) {
            foreach ($table['values'] as $value) {
                if ($value['meaning'] === null) {
                    return false;
                }
            }
        }

        return true;
    }
}
