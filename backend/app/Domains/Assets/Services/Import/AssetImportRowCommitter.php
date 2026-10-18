<?php

declare(strict_types=1);

namespace App\Domains\Assets\Services\Import;

use App\Domains\Assets\Actions\CreateAsset;
use App\Domains\Assets\Actions\CreatePcUnit;
use App\Enums\AssetImportRowOutcome;
use App\Enums\AssetImportRowStatus;
use App\Enums\ImportEntityType;
use App\Enums\ImportLocationResolution;
use App\Models\AssetImport;
use App\Models\AssetImportRow;
use App\Models\Room;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Saves one import row, atomically (Phase 2.10 R6, owner decision D3, SDD
 * DD-98).
 *
 * Each row runs in its own transaction: lock the ledger row, re-check it
 * against the **full** create rules — uniqueness and references included, so
 * the register accepts nothing the form would refuse — and then call the same
 * `CreatePcUnit` / `CreateAsset` Action the form calls, so an imported record
 * gets exactly the side effects of a hand-made one (its specification row, its
 * opening status history, its audit row). The ledger row is updated in the
 * same transaction as the record it describes, so the two can never disagree.
 *
 * The Action runs inside a savepoint. A unique violation there — someone else
 * created the same unit code a moment ago — rolls back only the savepoint and
 * is recorded as `skipped_duplicate` (`duplicate_at_save`); any other failure
 * is `failed` with a safe code. Neither ever touches an existing record.
 */
final class AssetImportRowCommitter
{
    public function __construct(
        private readonly CreatePcUnit $createPcUnit,
        private readonly CreateAsset $createAsset,
        private readonly ImportRowValidator $validator,
    ) {}

    public function commit(AssetImport $import, int $rowId, User $actor, Request $request): AssetImportRowOutcome
    {
        return DB::transaction(function () use ($import, $rowId, $actor, $request): AssetImportRowOutcome {
            /** @var AssetImportRow|null $row */
            $row = AssetImportRow::query()->whereKey($rowId)->lockForUpdate()->first();
            if ($row === null) {
                return AssetImportRowOutcome::NotAttempted;
            }
            if ($row->outcome !== null) {
                return $row->outcome;
            }

            $row->attempts++;
            $row->processed_at = now();

            if ($row->is_excluded || $row->status !== AssetImportRowStatus::Ready || $row->entity_type === null) {
                return $this->finish($row, AssetImportRowOutcome::NotAttempted, 'not_ready');
            }

            $payload = $row->payload ?? [];

            [$room, $roomProblem] = $this->room($import, $row);
            if ($roomProblem !== null) {
                return $this->finish($row, AssetImportRowOutcome::Failed, $roomProblem);
            }
            $payload['room'] = $room?->uuid;

            if ($row->entity_type === ImportEntityType::Asset) {
                $modelId = $row->catalogEntry?->resolvedModelId();
                if ($modelId === null) {
                    return $this->finish($row, AssetImportRowOutcome::Failed, 'catalog_unavailable');
                }
                $payload['hardware_model'] = $modelId;
            }

            if ($this->existsNow($row->entity_type, $payload)) {
                return $this->finish($row, AssetImportRowOutcome::SkippedDuplicate, 'duplicate_at_save');
            }

            $issues = $this->validator->validate($row->entity_type, $payload, full: true);
            if ($issues !== []) {
                return $this->finish($row, AssetImportRowOutcome::Failed, 'invalid_at_save:'.($issues[0]->field ?? 'row'));
            }

            $context = ImportRequestContext::auditContext($import, $row->row_number);

            try {
                $record = DB::transaction(fn () => $row->entity_type === ImportEntityType::PcUnit
                    ? $this->createPcUnit->handle($payload, $actor, $request, $context)
                    : $this->createAsset->handle($payload, $actor, $request, $context));
            } catch (UniqueConstraintViolationException) {
                return $this->finish($row, AssetImportRowOutcome::SkippedDuplicate, 'duplicate_at_save');
            } catch (Throwable $e) {
                report($e);

                return $this->finish($row, AssetImportRowOutcome::Failed, 'save_failed');
            }

            $row->forceFill([
                'created_pc_unit_id' => $row->entity_type === ImportEntityType::PcUnit ? $record->getKey() : null,
                'created_asset_id' => $row->entity_type === ImportEntityType::Asset ? $record->getKey() : null,
                'created_room_id' => $room?->getKey(),
                'applied_fields' => array_keys(array_filter($row->sources ?? [], fn (string $s): bool => $s !== 'default')),
            ]);

            return $this->finish($row, AssetImportRowOutcome::Created, null);
        });
    }

    private function finish(AssetImportRow $row, AssetImportRowOutcome $outcome, ?string $code): AssetImportRowOutcome
    {
        $row->outcome = $outcome;
        $row->failure_code = $code;
        $row->save();

        return $outcome;
    }

    /**
     * The room the row is saved in, re-checked now: it must still exist, and a
     * room not in use only if the administrator assigned it anyway.
     *
     * @return array{0: Room|null, 1: string|null}
     */
    private function room(AssetImport $import, AssetImportRow $row): array
    {
        $assignAnyway = false;

        if (isset($row->corrections['room'])) {
            $uuid = $row->corrections['room']['value'] ?? '';
            if (! is_string($uuid) || $uuid === '') {
                return [null, null];
            }
            $room = Room::query()->with('floor.building')->where('uuid', $uuid)->first();
            $assignAnyway = (bool) ($row->corrections['room']['assign_anyway'] ?? false);
        } else {
            $location = $row->location;
            if ($location === null || $location->resolution !== ImportLocationResolution::Room) {
                return [null, null];
            }
            $room = Room::query()->with('floor.building')->find($location->room_id);
            $assignAnyway = $location->assign_anyway;
        }

        if ($room === null) {
            return [null, 'room_unavailable'];
        }
        if (! ($room->is_active && $room->floor?->building?->is_active) && ! $assignAnyway) {
            return [null, 'room_unavailable'];
        }

        return [$room, null];
    }

    /**
     * A record with one of this row's identifiers exists now, compared as the
     * preview compared them: case-insensitively, archived records included.
     *
     * @param  array<string, mixed>  $payload
     */
    private function existsNow(ImportEntityType $type, array $payload): bool
    {
        $detector = new ImportDuplicateDetector;
        $detector->prepare([[$type, $payload]]);

        foreach ($detector->existing($type, $payload) as $issue) {
            if (in_array($issue->code, ['duplicate_existing', 'duplicate_archived'], true)) {
                return true;
            }
        }

        return false;
    }
}
