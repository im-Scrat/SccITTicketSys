<?php

declare(strict_types=1);

namespace App\Domains\Maintenance\Actions;

use App\Domains\Identity\Services\AuditLogger;
use App\Domains\Maintenance\Events\MaintenanceScheduled;
use App\Domains\Maintenance\Services\ChecklistInstantiator;
use App\Domains\Maintenance\Services\ConcurrentMaintenanceFinder;
use App\Enums\ActivityAction;
use App\Enums\MaintenanceStatus;
use App\Models\Asset;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceType;
use App\Models\PcUnit;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Open a maintenance record (SRS FR-MNT-001/002/004).
 *
 * Corrective and preventive are the *same* operation with a different type and
 * a different set of fields filled in — `ticket_id` nullable and
 * `scheduled_for` set is all "preventive with no originating ticket" means at
 * the data layer (FR-MNT-002). Inventing two write paths for one row would
 * guarantee they eventually diverged.
 *
 * Four things are decided here and never taken from the request:
 *
 *  1. **`status`** — a record is born `scheduled`. Every later move goes
 *     through `MaintenanceLifecycle`.
 *  2. **`technician_id`** — the caller, unless an administrator named someone
 *     else. The FormRequest already refused a technician naming a colleague;
 *     this is the second half of that rule, so an Action called from a command
 *     or a test cannot bypass it.
 *  3. **`created_by`** — the caller, always. FR-MNT-011 reaches records by
 *     *assigned* **or** *created*, so this column is load-bearing for
 *     authorization and can never be client-supplied.
 *  4. **the checklist** — issued from the type's default template in the same
 *     transaction, so a record cannot exist in a state where its completion
 *     gate has not been applied yet.
 */
class CreateMaintenanceRecord
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ChecklistInstantiator $checklists,
        private readonly ConcurrentMaintenanceFinder $concurrent,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated payload
     * @return array{record: MaintenanceRecord, concurrent: list<array<string, mixed>>}
     */
    public function handle(array $data, User $actor, ?Request $request = null): array
    {
        $type = MaintenanceType::query()->where('slug', $data['type'])->firstOrFail();
        $pcUnit = $this->resolve(PcUnit::class, $data['pc_unit'] ?? null);
        $asset = $this->resolve(Asset::class, $data['asset'] ?? null);
        $ticket = $this->resolve(Ticket::class, $data['ticket'] ?? null);
        $technician = $this->resolveTechnician($data['technician'] ?? null, $actor);

        // Read *before* the insert so the warning describes the situation the
        // caller was actually walking into, not one that includes their own row.
        $concurrent = $this->concurrent->forTarget($pcUnit, $asset);

        $record = DB::transaction(function () use ($data, $type, $pcUnit, $asset, $ticket, $technician, $actor, $request): MaintenanceRecord {
            $record = MaintenanceRecord::query()->create([
                'title' => $data['title'],
                'maintenance_type_id' => $type->getKey(),
                'pc_unit_id' => $pcUnit?->getKey(),
                'asset_id' => $asset?->getKey(),
                'ticket_id' => $ticket?->getKey(),
                'technician_id' => $technician->getKey(),

                'status' => MaintenanceStatus::Scheduled->value,
                'scheduled_for' => $data['scheduled_for'] ?? null,

                'diagnosis' => $data['diagnosis'] ?? null,
                'root_cause' => $data['root_cause'] ?? null,
                'resolution' => $data['resolution'] ?? null,
                'preventive_recommendation' => $data['preventive_recommendation'] ?? null,
                'downtime_minutes' => $data['downtime_minutes'] ?? null,
                'labor_hours' => $data['labor_hours'] ?? null,
                'cost' => $data['cost'] ?? null,

                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            $issued = $this->checklists->issue($record->load('type'));

            $this->audit->activity(
                ActivityAction::MaintenanceCreated,
                actor: $actor,
                subject: $record,
                properties: array_filter([
                    'type' => $type->slug,
                    'is_preventive' => $type->is_preventive,
                    'target' => $pcUnit->unit_code ?? $asset?->asset_tag,
                    'ticket' => $ticket?->ticket_number,
                    'technician' => $technician->uuid,
                    'scheduled_for' => $record->scheduled_for?->toIso8601String(),
                    'checklist_items' => $issued > 0 ? $issued : null,
                ], static fn (mixed $value): bool => $value !== null),
                request: $request,
                module: 'maintenance',
                description: "Maintenance opened: {$record->title}",
            );

            return $record;
        });

        /*
         * WP-2.7a — the notification seam (FR-MNT-002, FR-NOT-003 T5).
         *
         * Raised for every new record; the listener decides it is only news when
         * somebody other than the named technician opened it, because the
         * dispatcher drops the actor from every recipient list. That keeps the
         * "did I do this to myself?" question in one place rather than here.
         */
        MaintenanceScheduled::dispatch($record, $actor);

        return ['record' => $record, 'concurrent' => $concurrent];
    }

    /**
     * A public handle resolved to its model.
     *
     * uuid only — a client that guesses a numeric id gets nowhere
     * (NFR-SEC-001). The FormRequest has already proved the row exists and is
     * not archived; this simply fetches it.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<TModel>  $model
     * @return TModel|null
     */
    private function resolve(string $model, mixed $uuid)
    {
        if (! is_string($uuid) || $uuid === '') {
            return null;
        }

        return $model::query()->where('uuid', $uuid)->first();
    }

    /**
     * Who the work belongs to.
     *
     * Defaults to the caller. A named technician is honoured only for someone
     * entitled to reassign — the FormRequest says so to the user, and this says
     * so to every other caller.
     */
    private function resolveTechnician(mixed $uuid, User $actor): User
    {
        if (! is_string($uuid) || $uuid === '' || $uuid === $actor->uuid) {
            return $actor;
        }

        if (! $actor->can('reassignAny', MaintenanceRecord::class)) {
            return $actor;
        }

        return User::query()->where('uuid', $uuid)->first() ?? $actor;
    }
}
