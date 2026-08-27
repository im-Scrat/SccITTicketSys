<?php

declare(strict_types=1);

namespace App\Domains\Assets\Actions;

use App\Domains\Identity\Services\AuditLogger;
use App\Enums\ActivityAction;
use App\Models\PcSpecification;
use App\Models\PcUnit;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Edit a PC's specification snapshot (SRS FR-PC-003).
 *
 * The snapshot is a **display cache, not the source of truth**: the authoritative
 * record of what is physically inside a machine is
 * `pc_component_installations` (FR-PC-004). Keeping them separate is deliberate —
 * a technician can correct "16 GB" to "32 GB" on the spec sheet without
 * fabricating an installation event, and the installation history stays a record
 * of things that actually happened.
 *
 * Every edit is audited with a field-level old→new diff, so a spec change shows
 * up on the asset timeline like any other change rather than silently mutating.
 */
class UpsertPcSpecification
{
    /**
     * The editable specification fields — every column `pc_specifications`
     * actually has. Anything outside this list is ignored rather than written,
     * so a crafted payload cannot reach an unintended column.
     *
     * @var list<string>
     */
    public const FIELDS = [
        'cpu',
        'motherboard',
        'ram',
        'gpu',
        'storage_primary',
        'storage_secondary',
        'power_supply',
        'monitor',
        'keyboard',
        'mouse',
        'operating_system',
        'bios_version',
        'network_adapter',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(PcUnit $pcUnit, array $data, User $actor, Request $request): PcSpecification
    {
        $pcUnit->loadMissing('specification');
        $specification = $pcUnit->specification;

        $attributes = self::attributesFrom($data);
        $changes = [];

        foreach ($attributes as $field => $value) {
            $old = $specification?->{$field};

            if ($this->normalize($old) !== $this->normalize($value)) {
                $changes[$field] = ['from' => $this->normalize($old), 'to' => $this->normalize($value)];
            }
        }

        $specification = DB::transaction(function () use ($pcUnit, $specification, $attributes, $actor): PcSpecification {
            if ($specification === null) {
                $specification = PcSpecification::query()->create([
                    'pc_unit_id' => $pcUnit->getKey(),
                    ...$attributes,
                ]);
            } else {
                $specification->fill($attributes)->save();
            }

            // The spec belongs to the machine, so editing it touches the
            // machine's blame trail too.
            $pcUnit->forceFill(['updated_by' => $actor->getKey()])->save();

            return $specification;
        });

        $this->audit->activity(
            ActivityAction::PcSpecificationUpdated,
            actor: $actor,
            subject: $pcUnit,
            properties: $changes === [] ? null : ['changes' => $changes],
            request: $request,
            module: 'assets',
            description: "Specification updated for {$pcUnit->unit_code}",
        );

        return $specification->refresh();
    }

    /**
     * Narrow an arbitrary payload to the writable specification columns. Shared
     * with {@see CreatePcUnit}, which seeds the row at creation.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string|null>
     */
    public static function attributesFrom(array $data): array
    {
        $attributes = [];

        foreach (self::FIELDS as $field) {
            $value = $data[$field] ?? null;
            $value = is_string($value) ? trim($value) : $value;

            $attributes[$field] = ($value === '' || $value === null) ? null : (string) $value;
        }

        return $attributes;
    }

    private function normalize(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
