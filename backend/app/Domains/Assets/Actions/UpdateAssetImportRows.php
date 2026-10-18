<?php

declare(strict_types=1);

namespace App\Domains\Assets\Actions;

use App\Domains\Assets\Actions\Concerns\GuardsAssetImport;
use App\Domains\Assets\DTOs\ImportIssue;
use App\Domains\Assets\Services\Import\AssetImportValidation;
use App\Domains\Assets\Services\Import\ImportFieldDictionary;
use App\Enums\AssetImportRowStatus;
use App\Enums\AssetImportStatus;
use App\Models\AssetImport;
use App\Models\AssetImportRow;
use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Corrections, acknowledgements and exclusions on review rows (Phase 2.10 R6).
 *
 * A correction is stored beside the original cell, never over it, with who
 * made it and when — the row panel shows "as saved" next to "in your file".
 * A null correction removes it (back to the file's value); an empty one
 * deliberately clears the value. A row's own room can be chosen here too,
 * overriding its location text's decision for that row alone.
 *
 * After any change the row is checked again and every derived fact refreshed,
 * so a correction that resolves a clash with another row updates both.
 */
final class UpdateAssetImportRows
{
    use GuardsAssetImport;

    public function __construct(private readonly AssetImportValidation $validation) {}

    /**
     * @param  array<string, mixed>|null  $corrections  target => value|null; `room` => {uuid, assign_anyway}
     * @param  list<string>|null  $acknowledge
     */
    public function update(AssetImport $import, AssetImportRow $row, ?array $corrections, ?array $acknowledge, ?bool $exclude, User $actor): AssetImportRow
    {
        $this->assertStatus($import, [AssetImportStatus::Review]);

        DB::transaction(function () use ($import, $row, $corrections, $acknowledge, $exclude, $actor): void {
            $row->refresh();
            $rebuild = false;

            if ($corrections !== null) {
                $stored = $row->corrections ?? [];
                foreach ($corrections as $target => $value) {
                    if ($target === 'room') {
                        $stored = $this->roomCorrection($stored, $value, $actor);
                    } elseif ($value === null) {
                        unset($stored[$target]);
                    } else {
                        if (! ImportFieldDictionary::exists($target) || ! ImportFieldDictionary::appliesTo($target, $import->entity_mode) || ! is_scalar($value) || mb_strlen((string) $value) > 5000) {
                            throw ValidationException::withMessages(["corrections.{$target}" => 'That value cannot be saved for this column.']);
                        }
                        $stored[$target] = ['value' => trim((string) $value), 'by' => $actor->getKey(), 'at' => now()->toIso8601String()];
                    }
                }
                $row->corrections = $stored === [] ? null : $stored;
                $rebuild = true;
            }

            if ($acknowledge !== null) {
                $row->acknowledged = array_values(array_unique(array_filter($acknowledge, fn (string $code): bool => ImportIssue::isAcknowledgeable($code))));
            }

            if ($exclude !== null) {
                $row->is_excluded = $exclude;
                $row->status = $exclude ? AssetImportRowStatus::Excluded : AssetImportRowStatus::NeedsReview;
            }

            $row->save();
            $this->nextRevision($import);

            if ($rebuild) {
                $this->validation->revalidateRows($import, new Collection([$row->refresh()]));
            } else {
                $this->validation->refresh($import);
            }
        });

        return $row->refresh();
    }

    /**
     * Exclude or include many rows at once.
     *
     * @param  list<int>  $rowNumbers
     */
    public function bulk(AssetImport $import, array $rowNumbers, bool $exclude): int
    {
        $this->assertStatus($import, [AssetImportStatus::Review]);

        return DB::transaction(function () use ($import, $rowNumbers, $exclude): int {
            $count = $import->rows()->whereIn('row_number', $rowNumbers)->whereNull('outcome')->update([
                'is_excluded' => $exclude,
                'status' => $exclude ? AssetImportRowStatus::Excluded->value : AssetImportRowStatus::NeedsReview->value,
                'updated_at' => now(),
            ]);
            $this->nextRevision($import);
            $this->validation->refresh($import);

            return $count;
        });
    }

    /**
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    private function roomCorrection(array $stored, mixed $value, User $actor): array
    {
        if ($value === null) {
            unset($stored['room']);

            return $stored;
        }

        $uuid = is_array($value) ? ($value['uuid'] ?? '') : '';
        $assignAnyway = is_array($value) && ! empty($value['assign_anyway']);
        if ($uuid !== '') {
            $room = is_string($uuid) ? Room::query()->with('floor.building')->where('uuid', $uuid)->first() : null;
            if ($room === null) {
                throw ValidationException::withMessages(['corrections.room' => 'Choose a room from the list.']);
            }
            if (! ($room->is_active && $room->floor?->building?->is_active) && ! $assignAnyway) {
                throw ValidationException::withMessages(['corrections.room' => 'That room is not in use. Tick "Assign anyway" to use it.']);
            }
        }

        $stored['room'] = ['value' => (string) $uuid, 'assign_anyway' => $assignAnyway, 'by' => $actor->getKey(), 'at' => now()->toIso8601String()];

        return $stored;
    }
}
