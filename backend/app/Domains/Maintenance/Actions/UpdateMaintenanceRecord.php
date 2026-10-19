<?php

declare(strict_types=1);

namespace App\Domains\Maintenance\Actions;

use App\Domains\Identity\Services\AuditLogger;
use App\Domains\Maintenance\Events\MaintenanceRescheduled;
use App\Enums\ActivityAction;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceType;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Edit an open maintenance record, reassign it, archive it, restore it
 * (SRS FR-MNT-003/011; FR-AUD-003).
 *
 * Every method here writes an `activity_logs` row in the same transaction as
 * the change, with the **before and after values** of whatever moved. That is
 * what makes SDD DD-55's "no separate history table" honest: the timeline is
 * the audit log, so an update that skipped it would leave the record unable to
 * explain its own state.
 *
 * `status` is not writable from anywhere in this class. It moves through
 * `MaintenanceLifecycle` and nowhere else.
 */
class UpdateMaintenanceRecord
{
    /**
     * Fields a caller may edit directly, paired with the payload key they
     * arrive under. Anything outside this list — status, technician, target,
     * created_by, every timestamp — is not editable through this path.
     *
     * @var array<string, string>
     */
    private const EDITABLE = [
        'title' => 'title',
        'diagnosis' => 'diagnosis',
        'root_cause' => 'root_cause',
        'resolution' => 'resolution',
        'preventive_recommendation' => 'preventive_recommendation',
        'downtime_minutes' => 'downtime_minutes',
        'labor_hours' => 'labor_hours',
        'cost' => 'cost',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data  validated payload
     */
    public function handle(MaintenanceRecord $record, array $data, User $actor, ?Request $request = null): MaintenanceRecord
    {
        return DB::transaction(function () use ($record, $data, $actor, $request): MaintenanceRecord {
            $changes = [];

            foreach (self::EDITABLE as $column => $key) {
                // `array_key_exists`, not `isset`: clearing a field to null is a
                // deliberate edit, and `isset` would silently drop it.
                if (array_key_exists($key, $data)) {
                    $changes[$column] = $data[$key];
                }
            }

            if (array_key_exists('type', $data)) {
                $type = MaintenanceType::query()->where('slug', $data['type'])->first();

                if ($type !== null) {
                    $changes['maintenance_type_id'] = $type->getKey();
                }
            }

            if (array_key_exists('ticket', $data)) {
                $ticket = is_string($data['ticket']) && $data['ticket'] !== ''
                    ? Ticket::query()->where('uuid', $data['ticket'])->first()
                    : null;

                $changes['ticket_id'] = $ticket?->getKey();
            }

            $rescheduled = null;

            if (array_key_exists('scheduled_for', $data)) {
                $before = $record->scheduled_for?->toIso8601String();
                $changes['scheduled_for'] = $data['scheduled_for'];

                // A reschedule is its own event with its own audit entry. The
                // previous date is preserved in the log rather than silently
                // overwritten — the same discipline FR-WSR-006 will require of
                // the administrator reschedule in WP-2.6b.
                if ($before !== ($data['scheduled_for'] ?? null)) {
                    $rescheduled = ['from' => $before, 'to' => $data['scheduled_for'] ?? null];
                }
            }

            $original = $this->snapshot($record, array_keys($changes));

            $record->forceFill([...$changes, 'updated_by' => $actor->getKey()])->save();

            $diff = $this->diff($original, $this->snapshot($record->refresh(), array_keys($changes)));

            if ($diff !== []) {
                $this->audit->activity(
                    ActivityAction::MaintenanceUpdated,
                    actor: $actor,
                    subject: $record,
                    properties: ['changes' => $diff],
                    request: $request,
                    module: 'maintenance',
                    description: "Maintenance updated: {$record->title}",
                );
            }

            if ($rescheduled !== null) {
                $this->audit->activity(
                    ActivityAction::MaintenanceRescheduled,
                    actor: $actor,
                    subject: $record,
                    properties: array_filter([
                        ...$rescheduled,
                        'reason' => $data['reschedule_reason'] ?? null,
                    ], static fn (mixed $value): bool => $value !== null),
                    request: $request,
                    module: 'maintenance',
                    description: "Maintenance rescheduled: {$record->title}",
                );

                /*
                 * WP-2.7a — the notification seam (FR-MNT-003, FR-NOT-003 T6).
                 *
                 * Dispatched inside the transaction, which is safe here and
                 * deliberate: `NotificationDispatcher` defers delivery through
                 * `DB::afterCommit()`, so the job is pushed only once this
                 * commits and is dropped entirely if it rolls back. Raising it
                 * beside the audit entry keeps the reschedule's two consequences
                 * — the log line and the message — in the one branch that knows
                 * a reschedule actually happened.
                 */
                MaintenanceRescheduled::dispatch(
                    $record,
                    $rescheduled['from'],
                    $rescheduled['to'],
                    $actor,
                    $data['reschedule_reason'] ?? null,
                );
            }

            return $record;
        });
    }

    /**
     * Hand the record to a different technician (SRS FR-MNT-011).
     *
     * Administrator-only, enforced by the policy at the route. Both parties are
     * recorded, because "who used to hold this" is precisely the question an
     * audit of reassigned work asks.
     */
    public function reassign(MaintenanceRecord $record, User $technician, User $actor, ?Request $request = null): MaintenanceRecord
    {
        return DB::transaction(function () use ($record, $technician, $actor, $request): MaintenanceRecord {
            $previous = $record->technician;

            $record->forceFill([
                'technician_id' => $technician->getKey(),
                'updated_by' => $actor->getKey(),
            ])->save();

            $this->audit->activity(
                ActivityAction::MaintenanceReassigned,
                actor: $actor,
                subject: $record,
                properties: [
                    'from' => $previous?->uuid,
                    'from_name' => $previous?->fullName(),
                    'to' => $technician->uuid,
                    'to_name' => $technician->fullName(),
                ],
                request: $request,
                module: 'maintenance',
                description: "Maintenance reassigned to {$technician->fullName()}",
            );

            return $record->refresh();
        });
    }

    /**
     * Archive (soft delete).
     *
     * The row survives; only `deleted_at` is set. The audit entry outlives the
     * archive either way, which is what keeps the timeline honest about a record
     * having existed.
     */
    public function archive(MaintenanceRecord $record, User $actor, ?Request $request = null): void
    {
        DB::transaction(function () use ($record, $actor, $request): void {
            $record->forceFill(['updated_by' => $actor->getKey()])->save();
            $record->delete();

            $this->audit->activity(
                ActivityAction::MaintenanceArchived,
                actor: $actor,
                subject: $record,
                properties: ['status' => $record->status->value],
                request: $request,
                module: 'maintenance',
                description: "Maintenance archived: {$record->title}",
            );
        });
    }

    public function restore(MaintenanceRecord $record, User $actor, ?Request $request = null): MaintenanceRecord
    {
        return DB::transaction(function () use ($record, $actor, $request): MaintenanceRecord {
            $record->restore();
            $record->forceFill(['updated_by' => $actor->getKey()])->save();

            $this->audit->activity(
                ActivityAction::MaintenanceRestored,
                actor: $actor,
                subject: $record,
                properties: ['status' => $record->status->value],
                request: $request,
                module: 'maintenance',
                description: "Maintenance restored: {$record->title}",
            );

            return $record->refresh();
        });
    }

    /**
     * @param  list<string>  $columns
     * @return array<string, mixed>
     */
    private function snapshot(MaintenanceRecord $record, array $columns): array
    {
        $out = [];

        foreach ($columns as $column) {
            $value = $record->getAttribute($column);
            $out[$column] = $value instanceof \DateTimeInterface ? $value->format(DATE_ATOM) : $value;
        }

        return $out;
    }

    /**
     * Field-level old to new, so the timeline can render a real diff rather
     * than "something changed".
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function diff(array $before, array $after): array
    {
        $diff = [];

        foreach ($after as $column => $value) {
            if (($before[$column] ?? null) !== $value) {
                $diff[$column] = ['from' => $before[$column] ?? null, 'to' => $value];
            }
        }

        return $diff;
    }
}
