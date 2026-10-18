<?php

declare(strict_types=1);

namespace App\Domains\Assets\Services\Import;

use App\Enums\AssetImportEntityMode;
use App\Enums\AssetStatus;
use App\Enums\ComponentType;
use App\Enums\PcCondition;
use App\Enums\PcStatus;

/**
 * What a status, condition or category written in a spreadsheet means in the
 * register (Phase 2.10 R6, plan Appendix B.3).
 *
 * A value matches by its stored value, by the application's own label ("In
 * Service" is how the app itself shows `deployed`), or by a listed synonym. A
 * likely meaning is offered but must be confirmed; a value that genuinely
 * means different things in different schools ("CPU", "AVR", "system unit")
 * is never pre-selected. A value with no meaning chosen makes its rows invalid
 * (`unknown_value`) — the import never guesses a status.
 *
 * Mapping keys: `status.pc_unit`, `status.asset`, `condition`, `category`. In
 * a mixed file the category also decides the register, and `pc_unit` is one of
 * its meanings.
 */
final class ImportValueMapper
{
    /** The category meaning "this row is a PC unit" (mixed files only). */
    public const PC_UNIT = 'pc_unit';

    public const FIELDS = ['status.pc_unit', 'status.asset', 'condition', 'category'];

    /** @var array<string, list<string>> */
    private const ASSET_STATUS = [
        'new' => ['new', 'brand new', 'unused', 'unopened'],
        'in_stock' => ['available', 'in stock', 'stock', 'spare', 'for deployment', 'stored', 'idle'],
        'reserved' => ['assigned', 'reserved', 'allocated'],
        'deployed' => ['in service', 'deployed', 'in use', 'active', 'installed', 'operational', 'used'],
        'in_repair' => ['maintenance', 'in repair', 'under repair', 'repair', 'under maintenance', 'being repaired'],
        'out_of_service' => ['out of service', 'not in service', 'broken', 'defective', 'not working'],
        'in_transit' => ['in transit', 'transit', 'being transferred', 'for transfer'],
        'retired' => ['retired', 'decommissioned', 'phased out', 'obsolete', 'end of life', 'eol'],
        'disposed' => ['disposed', 'condemned', 'scrapped', 'junked', 'sold', 'donated', 'lost', 'written off'],
    ];

    /** @var array<string, string> */
    private const ASSET_STATUS_LIKELY = ['working' => 'deployed', 'for repair' => 'in_repair', 'unserviceable' => 'out_of_service'];

    /** @var array<string, list<string>> */
    private const PC_STATUS = [
        'available' => ['available', 'spare', 'unused', 'idle', 'free', 'vacant'],
        'assigned' => ['assigned', 'in use', 'allocated', 'occupied'],
        'online' => ['online', 'on', 'up', 'active', 'connected'],
        'offline' => ['offline', 'off', 'down', 'disconnected'],
        'under_maintenance' => ['under maintenance', 'maintenance', 'in repair', 'under repair', 'repair', 'being repaired'],
        'retired' => ['retired', 'decommissioned', 'condemned', 'disposed', 'phased out', 'obsolete'],
    ];

    /** @var array<string, string> */
    private const PC_STATUS_LIKELY = ['working' => 'online', 'for repair' => 'under_maintenance'];

    /** @var array<string, list<string>> */
    private const CONDITION = [
        'working' => ['working', 'good', 'ok', 'okay', 'functional', 'serviceable', 'fine', 'operational', 'excellent'],
        'faulty' => ['faulty', 'defective', 'broken', 'not working', 'unserviceable', 'damaged', 'bad', 'with issues', 'needs attention'],
        'for_repair' => ['for repair', 'needs repair', 'to be repaired', 'for maintenance', 'repairable'],
        'decommissioned' => ['decommissioned', 'condemned', 'beyond repair', 'ber', 'beyond economic repair', 'for disposal', 'junk'],
    ];

    /** @var array<string, string> */
    private const CONDITION_LIKELY = ['fair' => 'working', 'poor' => 'faulty'];

    /** @var array<string, list<string>> */
    private const CATEGORY = [
        'system_unit' => ['system unit', 'tower', 'desktop unit'],
        'printer' => ['printer', 'laser printer', 'inkjet', 'inkjet printer', 'multifunction printer', 'mfp', 'copier', 'photocopier'],
        'ups' => ['ups', 'uninterruptible power supply', 'battery backup'],
        'network_device' => ['router', 'switch', 'network switch', 'access point', 'ap', 'wifi router', 'wireless router', 'modem', 'firewall', 'hub', 'network device'],
        'scanner' => ['scanner', 'document scanner'],
        'projector' => ['projector', 'lcd projector', 'dlp projector', 'multimedia projector'],
        'cpu' => ['processor'],
        'ram' => ['ram', 'memory'],
        'gpu' => ['gpu', 'graphics card', 'video card'],
        'storage' => ['hdd', 'ssd', 'hard drive', 'hard disk', 'storage', 'external drive'],
        'power_supply' => ['psu', 'power supply'],
        'network_adapter' => ['nic', 'lan card', 'network card', 'wifi adapter'],
        'cooler' => ['cooler', 'fan', 'cpu fan', 'heatsink'],
        'chassis' => ['case', 'chassis', 'casing'],
        'monitor' => ['monitor', 'lcd monitor', 'led monitor', 'display', 'screen'],
        'keyboard' => ['keyboard'],
        'mouse' => ['mouse'],
        'peripheral' => ['speaker', 'speakers', 'headset', 'headphones', 'webcam', 'web camera', 'microphone', 'mic', 'peripheral'],
        'motherboard' => ['motherboard'],
        'other' => ['other', 'others', 'misc', 'miscellaneous'],
    ];

    /** Values that suggest a whole computer. */
    private const COMPUTER_LIKE = ['desktop', 'pc', 'computer', 'workstation', 'laptop', 'notebook', 'all in one', 'aio'];

    /**
     * The meanings a field can take, for the select.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(string $field, AssetImportEntityMode $mode): array
    {
        return match ($field) {
            'status.pc_unit' => PcStatus::options(),
            'status.asset' => AssetStatus::options(),
            'condition' => PcCondition::options(),
            'category' => [
                ...($mode === AssetImportEntityMode::Mixed ? [['value' => self::PC_UNIT, 'label' => 'PC unit']] : []),
                ...array_map(fn (ComponentType $t): array => ['value' => 'asset:'.$t->value, 'label' => 'Asset › '.$t->label()], ComponentType::cases()),
            ],
            default => [],
        };
    }

    /** Is this a meaning the field accepts? */
    public static function isValid(string $field, string $value, AssetImportEntityMode $mode): bool
    {
        return in_array($value, array_column(self::options($field, $mode), 'value'), true);
    }

    /**
     * Suggest a meaning for one raw value. `confidence` is exact, synonym,
     * likely, ambiguous or none.
     *
     * @return array{value: string|null, confidence: string, options: list<string>, hint: string|null}
     */
    public static function suggest(string $field, string $raw, AssetImportEntityMode $mode): array
    {
        $value = ImportFieldDictionary::normalize($raw);
        $out = fn (?string $v, string $c, array $options = [], ?string $hint = null): array => ['value' => $v, 'confidence' => $c, 'options' => $options, 'hint' => $hint];

        if ($value === '') {
            return $out(null, 'none');
        }

        if ($field === 'category') {
            return self::suggestCategory($value, $mode, $out);
        }

        [$cases, $dictionary, $likely] = match ($field) {
            'status.pc_unit' => [PcStatus::cases(), self::PC_STATUS, self::PC_STATUS_LIKELY],
            'status.asset' => [AssetStatus::cases(), self::ASSET_STATUS, self::ASSET_STATUS_LIKELY],
            'condition' => [PcCondition::cases(), self::CONDITION, self::CONDITION_LIKELY],
            default => [[], [], []],
        };

        foreach ($cases as $case) {
            if ($value === ImportFieldDictionary::normalize($case->value) || $value === ImportFieldDictionary::normalize($case->label())) {
                return $out($case->value, 'exact');
            }
        }
        foreach ($dictionary as $meaning => $words) {
            if (in_array($value, $words, true)) {
                return $out($meaning, 'synonym');
            }
        }
        if (isset($likely[$value])) {
            return $out($likely[$value], 'likely');
        }

        return $out(null, 'none');
    }

    /**
     * @param  callable(?string, string, list<string>=, ?string=): array{value: string|null, confidence: string, options: list<string>, hint: string|null}  $out
     * @return array{value: string|null, confidence: string, options: list<string>, hint: string|null}
     */
    private static function suggestCategory(string $value, AssetImportEntityMode $mode, callable $out): array
    {
        $mixed = $mode === AssetImportEntityMode::Mixed;

        if ($value === 'cpu') {
            return $out(null, 'ambiguous', array_values(array_filter([$mixed ? self::PC_UNIT : null, 'asset:system_unit', 'asset:cpu'])),
                'In many school inventories "CPU" means the whole system unit, not the processor.');
        }
        if ($value === 'avr') {
            return $out(null, 'ambiguous', ['asset:other', 'asset:ups'], 'An AVR regulates voltage and has no battery.');
        }
        if ($mixed && in_array($value, ['system unit', 'tower', 'desktop unit'], true)) {
            return $out(null, 'ambiguous', [self::PC_UNIT, 'asset:system_unit'],
                'PC units appear on the floor plan, in maintenance and in fault reports; choose Asset › System unit only for spare or stock units.');
        }
        if (in_array($value, self::COMPUTER_LIKE, true)) {
            return $out($mixed ? self::PC_UNIT : 'asset:system_unit', 'likely', [],
                'PC units appear on the floor plan, in maintenance and in fault reports; choose Asset › System unit only for spare or stock units.');
        }
        if ($mixed && in_array($value, ['pc unit', 'pc units'], true)) {
            return $out(self::PC_UNIT, 'exact');
        }

        foreach (ComponentType::cases() as $type) {
            if ($value === ImportFieldDictionary::normalize($type->value) || $value === ImportFieldDictionary::normalize($type->label())) {
                return $out('asset:'.$type->value, 'exact');
            }
        }
        foreach (self::CATEGORY as $meaning => $words) {
            if (in_array($value, $words, true)) {
                return $out('asset:'.$meaning, 'synonym');
            }
        }

        return $out(null, 'none');
    }
}
