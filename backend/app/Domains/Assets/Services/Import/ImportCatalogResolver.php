<?php

declare(strict_types=1);

namespace App\Domains\Assets\Services\Import;

use App\Enums\ImportCatalogMatch;
use App\Models\HardwareModel;
use Illuminate\Support\Collection;

/**
 * Matches an asset row's category, brand and model to the hardware catalog
 * (Phase 2.10 R6, owner decision D5).
 *
 * Nothing is written here. An unmatched combination becomes a **proposal**
 * that the administrator approves, edits, points at an existing model, or
 * rejects; approved proposals are created by `CreateCatalogEntry` only after
 * the import is confirmed.
 *
 * Matching is case-insensitive and sees **archived** entries too: a collision
 * with an archived model is `blocked`, because silently creating a second
 * "Epson L3210" beside the archived one would split its history. A row with no
 * brand whose model name exists under some manufacturer is `suggested` — the
 * likely answer is offered, not assumed.
 */
final class ImportCatalogResolver
{
    /** @var Collection<int, HardwareModel>|null */
    private ?Collection $models = null;

    /**
     * @param  array{component_type: string|null, manufacturer: string|null, model: string|null, model_number: string|null}  $parts
     */
    public static function normalizedKey(array $parts): ?string
    {
        if (($parts['component_type'] ?? null) === null || trim((string) ($parts['model'] ?? '')) === '') {
            return null;
        }

        return implode('|', [
            $parts['component_type'],
            mb_strtolower(trim((string) ($parts['manufacturer'] ?? ''))),
            mb_strtolower(trim((string) $parts['model'])),
        ]);
    }

    /**
     * @param  array{component_type: string|null, manufacturer: string|null, model: string|null, model_number: string|null}  $parts
     * @return array{match: ImportCatalogMatch, model_id: int|null, suggestions: list<array<string, mixed>>}
     */
    public function match(array $parts): array
    {
        $type = (string) $parts['component_type'];
        $brand = mb_strtolower(trim((string) ($parts['manufacturer'] ?? '')));
        $model = mb_strtolower(trim((string) $parts['model']));

        $sameName = $this->models()->filter(fn (HardwareModel $m): bool => mb_strtolower($m->model_name) === $model
            && $m->component?->component_type?->value === $type);

        $exact = $sameName->filter(fn (HardwareModel $m): bool => mb_strtolower((string) $m->component?->manufacturer?->name) === $brand);

        $live = $exact->first(fn (HardwareModel $m): bool => ! $this->archived($m));
        if ($live !== null) {
            return ['match' => ImportCatalogMatch::Existing, 'model_id' => $live->id, 'suggestions' => []];
        }

        $archived = $exact->first();
        if ($archived !== null) {
            return ['match' => ImportCatalogMatch::Blocked, 'model_id' => null, 'suggestions' => [$this->suggestion($archived, 'Archived in the catalog')]];
        }

        if ($brand === '') {
            $branded = $sameName->filter(fn (HardwareModel $m): bool => ! $this->archived($m))->values();
            if ($branded->isNotEmpty()) {
                return ['match' => ImportCatalogMatch::Suggested, 'model_id' => null, 'suggestions' => $branded->map(fn (HardwareModel $m): array => $this->suggestion($m, 'Same model name'))->all()];
            }
        }

        return ['match' => ImportCatalogMatch::New, 'model_id' => null, 'suggestions' => []];
    }

    private function archived(HardwareModel $model): bool
    {
        return $model->trashed()
            || $model->component === null
            || $model->component->trashed()
            || ($model->component->manufacturer !== null && $model->component->manufacturer->trashed());
    }

    /**
     * @return array<string, mixed>
     */
    private function suggestion(HardwareModel $model, string $reason): array
    {
        return [
            'model_id' => $model->id,
            'label' => self::modelLabel($model),
            'reason' => $reason,
            'archived' => $this->archived($model),
        ];
    }

    public static function modelLabel(HardwareModel $model): string
    {
        return trim(implode(' ', array_filter([$model->component?->manufacturer?->name, $model->model_name])))
            .($model->component?->component_type !== null ? ' · '.$model->component->component_type->label() : '');
    }

    /**
     * @return Collection<int, HardwareModel>
     */
    private function models(): Collection
    {
        return $this->models ??= HardwareModel::query()
            ->withTrashed()
            ->with(['component' => fn ($q) => $q->withTrashed()->with(['manufacturer' => fn ($m) => $m->withTrashed()])])
            ->get();
    }
}
