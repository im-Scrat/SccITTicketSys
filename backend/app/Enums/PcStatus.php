<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

/**
 * PC repair status — Phase 2.11 (SDD DD-100).
 *
 * Three values, one colour each, used identically on every screen (asset
 * lists, the Floorplan, QR, dashboards, filters). A fourth display category —
 * grey **Unassigned** — is *derived* from `pc_units.room_id IS NULL` and is
 * deliberately not a value here: a machine without a room still has a repair
 * status, and storing "unassigned" would overwrite it.
 *
 * The status describes the machine's *repair* state as recorded by people and
 * by the ticket/repair workflows (`PcStatusAutomation`). It never claims a
 * live connection state: the system has no telemetry, which is why the old
 * `online` / `offline` values were retired rather than kept.
 *
 * Legacy values (`assigned`, `online`, `offline`, `under_maintenance`,
 * `retired`) were mapped by migration `2026_10_19_100000_replace_pc_status_model`;
 * they can still appear inside historical `activity_logs` properties, which is
 * what {@see legacyLabel()} and {@see fromStored()} are for.
 */
enum PcStatus: string
{
    use HasValues;

    case Available = 'available';
    case PendingRepair = 'pending_repair';
    case NeedsFix = 'needs_fix';

    /**
     * Legacy value => the value it was migrated to. The single place the old
     * domain is spelled out in application code (the migration keeps its own
     * frozen copy, as migrations must).
     *
     * @var array<string, string>
     */
    public const LEGACY_MAP = [
        'assigned' => 'available',
        'online' => 'available',
        'under_maintenance' => 'pending_repair',
        'offline' => 'needs_fix',
        // Retired machines were archived; the few that could not be (open
        // tickets, installed parts) were kept live as needs_fix.
        'retired' => 'needs_fix',
    ];

    /** @var array<string, string> legacy value => its pre-2.11 label */
    private const LEGACY_LABELS = [
        'assigned' => 'Assigned',
        'online' => 'Online',
        'offline' => 'Offline',
        'under_maintenance' => 'Under Maintenance',
        'retired' => 'Retired',
    ];

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Available',
            self::PendingRepair => 'Pending Repair',
            self::NeedsFix => 'Needs Fix',
        };
    }

    /**
     * Presentation tone, returned by the API so the client never maps a status
     * to a colour on its own. The client pairs it with the label and an icon —
     * status is never colour alone (FR-FP-004).
     *
     * @return 'success'|'warning'|'danger'
     */
    public function tone(): string
    {
        return match ($this) {
            self::Available => 'success',
            self::PendingRepair => 'warning',
            self::NeedsFix => 'danger',
        };
    }

    /**
     * A stored value, tolerating the legacy domain.
     *
     * Production recreates its containers before `migrate` runs
     * (`scripts/deploy-prod.sh`, steps 5→6), so for a few seconds new code can
     * read rows that still hold legacy values. Reading them as their mapped
     * value keeps that window from turning every PC page into a 500.
     */
    public static function fromStored(string $value): self
    {
        return self::tryFrom($value) ?? self::from(self::LEGACY_MAP[$value] ?? $value);
    }

    /**
     * Human label for any value that may appear in history — current or
     * legacy. Unknown strings come back unchanged rather than throwing: an
     * audit timeline must render whatever it was given.
     */
    public static function labelFor(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return self::tryFrom($value)?->label() ?? self::LEGACY_LABELS[$value] ?? $value;
    }
}
