<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

/**
 * Hardware catalog categories (SRS FR-AST-001). Drives
 * `hardware_components.component_type` and the asset directory's category filter.
 *
 * Phase 2.5 widened this domain additively with whole-equipment categories —
 * system units, printers, UPS, network devices, scanners and projectors — which
 * the original set only reached through `peripheral`/`other` (SDD DD-32). Every
 * pre-existing value stays legal. The DB CHECK is built from {@see values()}.
 */
enum ComponentType: string
{
    use HasValues;

    // Whole equipment.
    case SystemUnit = 'system_unit';
    case Printer = 'printer';
    case Ups = 'ups';
    case NetworkDevice = 'network_device';
    case Scanner = 'scanner';
    case Projector = 'projector';

    // Internal components.
    case Cpu = 'cpu';
    case Motherboard = 'motherboard';
    case Ram = 'ram';
    case Gpu = 'gpu';
    case Storage = 'storage';
    case PowerSupply = 'power_supply';
    case NetworkAdapter = 'network_adapter';
    case Cooler = 'cooler';
    case Chassis = 'chassis';

    // Attached devices.
    case Monitor = 'monitor';
    case Keyboard = 'keyboard';
    case Mouse = 'mouse';
    case Peripheral = 'peripheral';

    case Other = 'other';

    /**
     * Human label. Overridden only where the derived default would be wrong —
     * acronyms and multi-word values the `HasValues` fallback title-cases badly.
     */
    public function label(): string
    {
        return match ($this) {
            self::SystemUnit => 'System Unit',
            self::Ups => 'UPS',
            self::NetworkDevice => 'Network Device',
            self::NetworkAdapter => 'Network Adapter',
            self::PowerSupply => 'Power Supply',
            self::Cpu => 'CPU',
            self::Gpu => 'GPU',
            self::Ram => 'RAM',
            default => ucwords(str_replace('_', ' ', $this->value)),
        };
    }

    /**
     * Whole pieces of equipment that stand on their own in a room, as opposed to
     * parts installed inside a PC. The directory groups its category filter by
     * this split so "show me the printers" is not buried among RAM sticks.
     */
    public function isEquipment(): bool
    {
        return match ($this) {
            self::SystemUnit, self::Printer, self::Ups,
            self::NetworkDevice, self::Scanner, self::Projector => true,
            default => false,
        };
    }

    /** Devices attached to a PC rather than installed inside it. */
    public function isPeripheral(): bool
    {
        return match ($this) {
            self::Monitor, self::Keyboard, self::Mouse, self::Peripheral => true,
            default => false,
        };
    }

    /**
     * Grouping key for the category filter's option groups.
     *
     * @return 'equipment'|'peripheral'|'component'
     */
    public function group(): string
    {
        return match (true) {
            $this->isEquipment() => 'equipment',
            $this->isPeripheral() => 'peripheral',
            default => 'component',
        };
    }
}
