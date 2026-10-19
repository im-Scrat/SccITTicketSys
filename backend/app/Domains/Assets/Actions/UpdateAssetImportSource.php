<?php

declare(strict_types=1);

namespace App\Domains\Assets\Actions;

use App\Domains\Assets\Actions\Concerns\GuardsAssetImport;
use App\Domains\Assets\Jobs\ReadAssetImportFileJob;
use App\Enums\AssetImportStatus;
use App\Models\AssetImport;

/**
 * Reads the file again with another sheet, header row, delimiter or encoding
 * (Phase 2.10 R6). The rows are replaced: they described a different table.
 */
final class UpdateAssetImportSource
{
    use GuardsAssetImport;

    /**
     * @param  array{sheet?: string|null, header_row?: int|null, delimiter?: string|null, encoding?: string|null}  $options
     */
    public function handle(AssetImport $import, array $options): AssetImport
    {
        $this->assertStatus($import, [AssetImportStatus::Mapping, AssetImportStatus::Review, AssetImportStatus::Unreadable]);

        $import->forceFill([
            'source_options' => array_filter($options, fn ($v): bool => $v !== null && $v !== ''),
            'status' => AssetImportStatus::Reading,
            'error_code' => null,
        ])->save();
        $this->nextRevision($import);

        ReadAssetImportFileJob::dispatch($import->getKey());

        return $import->refresh();
    }
}
