<?php

declare(strict_types=1);

namespace App\Domains\Assets\Http\Resources;

use App\Domains\Assets\Services\Import\AssetImportPlanner;
use App\Domains\Assets\Services\Import\AssetImportValues;
use App\Domains\Assets\Services\Import\ImportFieldDictionary;
use App\Domains\Assets\Services\Import\ImportValueMapper;
use App\Enums\AssetImportOutcome;
use App\Enums\AssetImportRowOutcome;
use App\Enums\AssetImportStatus;
use App\Enums\AssetStatus;
use App\Enums\PcCondition;
use App\Enums\PcStatus;
use App\Models\AssetImport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One import, as the wizard needs it at its current step (Phase 2.10 R6).
 *
 * No storage path, hash input, raw request metadata or numeric id. The plan
 * shown while reviewing is computed now; once confirmed, the plan that was
 * confirmed is shown instead, and the outcome only ever as the finalizer
 * stored it.
 *
 * @mixin AssetImport
 */
class AssetImportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $mapping = $this->mapping ?? [];
        $editable = in_array($this->status, [AssetImportStatus::Mapping, AssetImportStatus::Review], true);
        $values = app(AssetImportValues::class);

        $duplicate = AssetImport::query()
            ->where('sha256', $this->sha256)
            ->whereKeyNot($this->getKey())
            ->whereNotIn('status', [AssetImportStatus::Cancelled->value, AssetImportStatus::Expired->value])
            ->latest('id')
            ->first(['uuid', 'original_filename', 'created_at', 'status', 'outcome']);

        $result = $this->result;
        $progress = null;
        if ($this->status->isRunning()) {
            $total = (int) ($this->plan['total'] ?? 0);
            // Rows this save has finished with; preview duplicates were never part of it.
            $done = $this->rows()->whereNotNull('outcome')
                ->where(fn ($q) => $q->whereNull('failure_code')->orWhere('failure_code', '!=', 'duplicate_preview'))
                ->where('processed_at', '>=', $this->started_at ?? now())->count();
            $created = $this->rows()->where('outcome', AssetImportRowOutcome::Created->value)->count();
            $progress = ['done' => min($done, $total), 'total' => $total, 'created' => $created];
        }

        return [
            'id' => $this->uuid,
            'entity_mode' => $this->entity_mode->value,
            'entity_mode_label' => $this->entity_mode->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'outcome' => $this->outcome?->value,
            'revision' => $this->revision,
            'file' => [
                'name' => $this->original_filename,
                'extension' => $this->extension,
                'size_bytes' => $this->size_bytes,
                'available' => $this->hasFile(),
            ],
            'duplicate_of' => $duplicate === null ? null : [
                'id' => $duplicate->uuid,
                'name' => $duplicate->original_filename,
                'created_at' => $duplicate->created_at?->toIso8601String(),
                'outcome' => $duplicate->outcome?->value,
            ],
            'source' => [
                'sheets' => $this->source_meta['sheets'] ?? [],
                'sheet' => $this->source_meta['sheet'] ?? null,
                'header_row' => $this->source_meta['header_row'] ?? null,
                'encoding' => $this->source_meta['encoding'] ?? null,
                'delimiter' => ($this->source_meta['delimiter'] ?? null) === "\t" ? 'tab' : ($this->source_meta['delimiter'] ?? null),
                'columns' => $this->source_meta['columns'] ?? null,
                'template_match' => (bool) ($this->source_meta['template_match'] ?? false),
                'error' => $this->status === AssetImportStatus::Unreadable ? ($this->source_meta['error'] ?? 'This file could not be read.') : null,
                'error_code' => $this->error_code,
            ],
            'columns' => $this->headers ?? [],
            'mapping' => (object) array_map('strval', $mapping),
            'value_mappings' => (object) array_map(fn (array $m): object => (object) $m, $this->value_mappings ?? []),
            'defaults' => (object) ($this->defaults ?? []),
            'date_order' => $this->date_order?->value,
            'targets' => ImportFieldDictionary::forClient($this->entity_mode),
            'default_options' => [
                'category' => ImportValueMapper::options('category', $this->entity_mode),
                'pc_status' => PcStatus::options(),
                'asset_status' => AssetStatus::options(),
                'condition' => PcCondition::options(),
            ],
            'meaning_tables' => $editable ? $values->meaningTables($this->resource, $mapping) : [],
            'date_summary' => $editable ? $values->dateSummary($this->resource, $mapping) : null,
            'row_counts' => $this->row_counts,
            'total_rows' => $this->total_rows,
            'plan' => $this->status === AssetImportStatus::Review ? app(AssetImportPlanner::class)->plan($this->resource) : $this->plan,
            'progress' => $progress,
            'result' => $result,
            'outcome_label' => $this->outcome?->label(),
            'created_by' => $this->creator?->fullName(),
            'confirmed_by' => $this->confirmer?->fullName(),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'stop_requested' => $this->stop_requested_at !== null,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'can' => [
                'cancel' => $this->status->isDraft(),
                'stop' => $this->status->isRunning() && $this->stop_requested_at === null,
                'retry' => $this->status === AssetImportStatus::Finished
                    && in_array($this->outcome, [AssetImportOutcome::Partial, AssetImportOutcome::Failed, AssetImportOutcome::Stopped], true)
                    && $this->rows()->whereIn('outcome', [AssetImportRowOutcome::Failed->value, AssetImportRowOutcome::NotAttempted->value])->exists(),
                'download_file' => $this->hasFile(),
            ],
        ];
    }
}
