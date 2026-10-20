<?php

declare(strict_types=1);

namespace App\Enums;

use App\Domains\Assets\Services\AssetLifecycle;
use App\Support\Concerns\HasValues;

/**
 * Serialized-asset lifecycle (SRS FR-AST-002/005).
 *
 * Phase 2.5 widened this domain additively with `New` and `OutOfService` — every
 * value that was legal before stays legal, so no data migration was needed
 * (SDD DD-32; the technique is DD-18's, first used for the `rejected` user
 * status). The DB `assets_status_check` and both `asset_status_history` CHECKs
 * are built from {@see values()}, so this enum is the single source of truth.
 *
 * `Archived` is deliberately **not** a case: archiving is a soft delete
 * (`deleted_at`), not a status. An archived asset retains the lifecycle status it
 * held when it was archived, which is what makes a restore meaningful.
 */
enum AssetStatus: string
{
    use HasValues;

    case New = 'new';
    case InStock = 'in_stock';
    case Reserved = 'reserved';
    case Deployed = 'deployed';
    case InRepair = 'in_repair';
    case OutOfService = 'out_of_service';
    case InTransit = 'in_transit';
    case Retired = 'retired';
    case Disposed = 'disposed';

    /**
     * The client-facing vocabulary. This overrides the `HasValues` default
     * (which would derive "In Stock" from the backing value) so the operational
     * language the Client uses — Available, Assigned, In Service, Under
     * maintenance — is the one the product speaks, without renaming a baselined
     * column value. "Under maintenance" (was "Maintenance") matches the add-asset
     * form and the dashboard tile wording (owner request 2026-10-20, DD-114).
     */
    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::InStock => 'Available',
            self::Reserved => 'Assigned',
            self::Deployed => 'In Service',
            self::InRepair => 'Under maintenance',
            self::OutOfService => 'Out of service',
            self::InTransit => 'In Transit',
            self::Retired => 'Retired',
            self::Disposed => 'Disposed',
        };
    }

    /**
     * The opening statuses the add-asset form offers (owner request 2026-10-20,
     * SDD DD-114): Available, Under maintenance, Out of service. A new asset is
     * either ready, being worked on, or unusable; the rest of the lifecycle is
     * reached through transitions, each of which writes history. The import
     * keeps the full vocabulary — a register may describe any state.
     *
     * @return list<string>
     */
    public static function formValues(): array
    {
        return [self::InStock->value, self::InRepair->value, self::OutOfService->value];
    }

    /**
     * Presentation tone for badges and metric tiles. Returned by the API so the
     * client never hard-codes a status colour (DESIGN.md: status hues are
     * universal and never re-skinned).
     *
     * @return 'neutral'|'success'|'warning'|'danger'
     */
    public function tone(): string
    {
        return match ($this) {
            self::Deployed => 'success',
            self::InRepair, self::InTransit, self::OutOfService => 'warning',
            self::Retired, self::Disposed => 'danger',
            self::New, self::InStock, self::Reserved => 'neutral',
        };
    }

    /**
     * Terminal states end the asset's working life. Entering one requires
     * `assets.dispose` rather than `assets.update` (SDD DD-33), and no transition
     * leads back out of {@see Disposed}.
     */
    public function isTerminal(): bool
    {
        return $this === self::Retired || $this === self::Disposed;
    }

    /**
     * True when the asset still counts as part of the live estate. Mirrors the
     * exclusion `LocationGuard` already applies when deciding whether a room
     * still holds occupants.
     */
    public function isLive(): bool
    {
        return ! $this->isTerminal();
    }

    /**
     * Statuses in which an asset is **in service** in a room, and so may have a
     * fault reported against it from the report form (SDD DD-78; owner decision
     * 2026-10-16): In Service, Maintenance and Out of Service.
     *
     * Narrower than {@see isLive()} on purpose. New, Available (spare stock),
     * Assigned and In Transit equipment is not in use by anyone, so nobody can
     * meet a fault on it — and listing it room by room would turn the form's
     * equipment list into a located inventory of portable stock.
     *
     * @return list<string>
     */
    public static function reportableValues(): array
    {
        return [self::Deployed->value, self::InRepair->value, self::OutOfService->value];
    }

    /**
     * Legal next states (SRS FR-AST-005; enforced by
     * {@see AssetLifecycle}).
     *
     * `Disposed` is absorbing — a disposed asset has left the organization, so it
     * has no outgoing transitions. Everything else can reach `Retired`/`Disposed`
     * because an asset can fail or be written off at any point in its life.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::New => [self::InStock, self::Reserved, self::Deployed, self::InTransit, self::Retired, self::Disposed],
            self::InStock => [self::Reserved, self::Deployed, self::InRepair, self::InTransit, self::OutOfService, self::Retired, self::Disposed],
            self::Reserved => [self::InStock, self::Deployed, self::InTransit, self::InRepair, self::OutOfService, self::Retired, self::Disposed],
            self::Deployed => [self::InStock, self::InRepair, self::OutOfService, self::InTransit, self::Retired, self::Disposed],
            self::InRepair => [self::InStock, self::Deployed, self::OutOfService, self::Retired, self::Disposed],
            self::OutOfService => [self::InStock, self::InRepair, self::Retired, self::Disposed],
            self::InTransit => [self::InStock, self::Deployed, self::Reserved, self::InRepair, self::Retired, self::Disposed],
            self::Retired => [self::Disposed, self::InStock],
            self::Disposed => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
