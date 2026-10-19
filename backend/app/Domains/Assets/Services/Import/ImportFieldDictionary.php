<?php

declare(strict_types=1);

namespace App\Domains\Assets\Services\Import;

use App\Enums\AssetImportEntityMode;
use App\Enums\ImportEntityType;
use Normalizer;

/**
 * Every place an imported column can go, and the header words that point
 * there (Phase 2.10 R6, plan Appendix B.1).
 *
 * A target is a destination in the register, named once: its label, the group
 * the mapping select lists it under, which registers it applies to, the
 * payload key it fills for each (the key the shared create rules validate),
 * the header the downloadable template uses, and its synonyms. The client is
 * served this table, so the mapping screen and the server can never disagree
 * about what a column means.
 *
 * Confidence is deliberately conservative. Only an exact template header or a
 * listed synonym is pre-selected without comment; a "likely" match (a listed
 * likely word, a header that *contains* a multi-word synonym, or one a typo
 * away from a single synonym) is pre-selected but flagged "Check this"; an
 * ambiguous header ("Description", "Vendor", "Code") is left unselected and
 * blocks "Check rows" until the administrator chooses.
 */
final class ImportFieldDictionary
{
    public const GROUPS = ['Identity', 'Details', 'Network', 'Purchase', 'Location', 'Specification'];

    /**
     * key => definition. `pc` / `asset` are the payload keys for each register
     * (null: not imported for that register); keys starting `location.` or
     * `catalog.` feed the location matcher and catalog resolver instead.
     *
     * @var array<string, array{label: string, group: string, template: string, pc: string|null, asset: string|null, synonyms: list<string>, likely?: list<string>, hint?: string}>
     */
    private const TARGETS = [
        'unit_code' => ['label' => 'Unit code', 'group' => 'Identity', 'template' => 'Unit code', 'pc' => 'unit_code', 'asset' => null,
            'synonyms' => ['unit code', 'unit id', 'unit no', 'unit number', 'pc code', 'pc id', 'pc no', 'pc number', 'computer code', 'computer id', 'computer no', 'computer number', 'workstation id', 'workstation no', 'station no', 'station number', 'terminal no']],
        'name' => ['label' => 'Name', 'group' => 'Identity', 'template' => 'Name', 'pc' => 'pc_name', 'asset' => 'name',
            'synonyms' => ['name', 'pc name', 'device name', 'machine name', 'workstation name', 'station name', 'asset name', 'item name', 'item', 'equipment name', 'equipment', 'article', 'label']],
        'asset_tag' => ['label' => 'Asset tag', 'group' => 'Identity', 'template' => 'Asset tag', 'pc' => 'asset_tag', 'asset' => 'asset_tag',
            'synonyms' => ['asset tag', 'asset tag no', 'asset no', 'asset number', 'asset id', 'asset code', 'property no', 'property number', 'property tag', 'property code', 'inventory no', 'inventory number', 'inventory tag', 'inventory code', 'tag', 'tag no', 'tag number']],
        'serial_number' => ['label' => 'Serial number', 'group' => 'Identity', 'template' => 'Serial number', 'pc' => 'serial_number', 'asset' => 'serial_number',
            'synonyms' => ['serial', 'serial no', 'serial number', 'serial num', 's n', 'sn', 'service tag', 'manufacturer serial', 'mfr serial', 'device serial']],
        'hostname' => ['label' => 'Hostname', 'group' => 'Network', 'template' => 'Hostname', 'pc' => 'hostname', 'asset' => null,
            'synonyms' => ['hostname', 'host name', 'host', 'dns name', 'network name', 'netbios name', 'fqdn']],
        'barcode' => ['label' => 'Barcode', 'group' => 'Identity', 'template' => 'Barcode', 'pc' => null, 'asset' => 'barcode',
            'synonyms' => ['barcode', 'bar code', 'upc', 'ean', 'barcode no']],
        'category' => ['label' => 'Category', 'group' => 'Details', 'template' => 'Category', 'pc' => null, 'asset' => 'catalog.component_type',
            'synonyms' => ['category', 'type', 'asset type', 'equipment type', 'item type', 'device type', 'class', 'classification', 'kind', 'asset category', 'equipment category']],
        'brand' => ['label' => 'Brand', 'group' => 'Details', 'template' => 'Brand', 'pc' => 'brand', 'asset' => 'catalog.manufacturer',
            'synonyms' => ['brand', 'make', 'manufacturer', 'mfr', 'mfg', 'maker', 'brand name', 'oem']],
        'model' => ['label' => 'Model', 'group' => 'Details', 'template' => 'Model', 'pc' => 'model', 'asset' => 'catalog.model',
            'synonyms' => ['model', 'model name', 'product', 'product name', 'model description']],
        'model_number' => ['label' => 'Model number', 'group' => 'Details', 'template' => 'Model number', 'pc' => null, 'asset' => 'catalog.model_number',
            'synonyms' => ['part no', 'part number', 'pn', 'mpn', 'sku', 'product code', 'product no', 'model code']],
        'status' => ['label' => 'Status', 'group' => 'Details', 'template' => 'Status', 'pc' => 'status', 'asset' => 'status',
            'synonyms' => ['status', 'pc status', 'asset status', 'state', 'operational status', 'lifecycle status', 'deployment status']],
        'condition' => ['label' => 'Condition', 'group' => 'Details', 'template' => 'Condition', 'pc' => 'current_condition', 'asset' => 'condition',
            'synonyms' => ['condition', 'current condition', 'physical condition', 'working condition', 'state of repair', 'functionality']],
        'notes' => ['label' => 'Notes', 'group' => 'Details', 'template' => 'Notes', 'pc' => 'notes', 'asset' => 'notes',
            'synonyms' => ['notes', 'note', 'remarks', 'remark', 'comments', 'comment', 'observations', 'additional info', 'other info']],
        'ip_address' => ['label' => 'IP address', 'group' => 'Network', 'template' => 'IP address', 'pc' => 'ip_address', 'asset' => null,
            'synonyms' => ['ip', 'ip address', 'ipv4', 'ipv4 address', 'ip addr', 'static ip', 'lan ip']],
        'mac_address' => ['label' => 'MAC address', 'group' => 'Network', 'template' => 'MAC address', 'pc' => 'mac_address', 'asset' => null,
            'synonyms' => ['mac', 'mac address', 'mac addr', 'physical address', 'ethernet address', 'hardware address', 'lan mac']],
        'purchase_date' => ['label' => 'Purchase date', 'group' => 'Purchase', 'template' => 'Purchase date', 'pc' => 'purchase_date', 'asset' => 'purchase_date',
            'synonyms' => ['purchase date', 'date purchased', 'date of purchase', 'purchased', 'purchased on', 'acquisition date', 'date acquired', 'acquired', 'date of acquisition', 'acquired on']],
        'warranty_expiration' => ['label' => 'Warranty expiration', 'group' => 'Purchase', 'template' => 'Warranty expiration', 'pc' => 'warranty_expiration', 'asset' => 'warranty_expiration',
            'synonyms' => ['warranty', 'warranty expiry', 'warranty expiration', 'warranty expiration date', 'warranty end', 'warranty end date', 'warranty until', 'warranty expires', 'end of warranty', 'warranty valid until']],
        'purchase_price' => ['label' => 'Purchase price', 'group' => 'Purchase', 'template' => 'Purchase price', 'pc' => null, 'asset' => 'purchase_price',
            'synonyms' => ['price', 'cost', 'unit cost', 'unit price', 'purchase price', 'purchase cost', 'acquisition cost', 'amount'], 'likely' => ['value']],
        'supplier' => ['label' => 'Supplier', 'group' => 'Purchase', 'template' => 'Supplier', 'pc' => null, 'asset' => 'supplier',
            'synonyms' => ['supplier', 'seller', 'dealer', 'distributor', 'purchased from', 'supplied by', 'source'],
            'hint' => 'Matched by name to an existing supplier; suppliers are never created.'],
        'custodian_email' => ['label' => 'Custodian email', 'group' => 'Details', 'template' => 'Custodian email', 'pc' => null, 'asset' => 'technician',
            'synonyms' => ['custodian', 'custodian email', 'custodian e mail', 'assigned to', 'assigned technician', 'technician', 'technician email', 'responsible person', 'accountable officer', 'accountable person'],
            'hint' => 'The email of a technician or administrator.'],

        'spec_cpu' => ['label' => 'Processor', 'group' => 'Specification', 'template' => 'Processor', 'pc' => 'specification.cpu', 'asset' => null,
            'synonyms' => ['processor', 'processor model', 'processor type', 'proc', 'cpu model'], 'likely' => ['cpu'],
            'hint' => 'In some school inventories "CPU" means the system unit; choose this only if the column lists processors such as Intel Core i5-10400.'],
        'spec_motherboard' => ['label' => 'Motherboard', 'group' => 'Specification', 'template' => 'Motherboard', 'pc' => 'specification.motherboard', 'asset' => null,
            'synonyms' => ['motherboard', 'mobo', 'mainboard', 'main board', 'system board', 'board']],
        'spec_ram' => ['label' => 'RAM', 'group' => 'Specification', 'template' => 'RAM', 'pc' => 'specification.ram', 'asset' => null,
            'synonyms' => ['ram', 'memory', 'ram size', 'memory size', 'installed ram', 'ram capacity', 'ram gb', 'memory gb']],
        'spec_gpu' => ['label' => 'Graphics', 'group' => 'Specification', 'template' => 'Graphics', 'pc' => 'specification.gpu', 'asset' => null,
            'synonyms' => ['gpu', 'graphics', 'graphics card', 'video card', 'vga', 'display adapter', 'graphics adapter', 'video']],
        'spec_storage_primary' => ['label' => 'Primary storage', 'group' => 'Specification', 'template' => 'Primary storage', 'pc' => 'specification.storage_primary', 'asset' => null,
            'synonyms' => ['storage', 'storage 1', 'primary storage', 'primary drive', 'hdd', 'ssd', 'hard drive', 'hard disk', 'disk', 'drive', 'drive 1', 'disk 1', 'storage capacity']],
        'spec_storage_secondary' => ['label' => 'Secondary storage', 'group' => 'Specification', 'template' => 'Secondary storage', 'pc' => 'specification.storage_secondary', 'asset' => null,
            'synonyms' => ['storage 2', 'secondary storage', 'secondary drive', 'drive 2', 'disk 2', 'hdd 2', 'ssd 2', 'second drive', 'additional storage']],
        'spec_power_supply' => ['label' => 'Power supply', 'group' => 'Specification', 'template' => 'Power supply', 'pc' => 'specification.power_supply', 'asset' => null,
            'synonyms' => ['psu', 'power supply', 'power supply unit', 'psu watts']],
        'spec_monitor' => ['label' => 'Monitor', 'group' => 'Specification', 'template' => 'Monitor', 'pc' => 'specification.monitor', 'asset' => null,
            'synonyms' => ['monitor', 'display', 'screen', 'lcd', 'led monitor', 'monitor model', 'monitor brand']],
        'spec_keyboard' => ['label' => 'Keyboard', 'group' => 'Specification', 'template' => 'Keyboard', 'pc' => 'specification.keyboard', 'asset' => null,
            'synonyms' => ['keyboard', 'kb', 'keyboard model']],
        'spec_mouse' => ['label' => 'Mouse', 'group' => 'Specification', 'template' => 'Mouse', 'pc' => 'specification.mouse', 'asset' => null,
            'synonyms' => ['mouse', 'mouse model']],
        'spec_operating_system' => ['label' => 'Operating system', 'group' => 'Specification', 'template' => 'Operating system', 'pc' => 'specification.operating_system', 'asset' => null,
            'synonyms' => ['os', 'operating system', 'o s', 'windows version', 'os version', 'os edition', 'platform']],
        'spec_bios_version' => ['label' => 'BIOS version', 'group' => 'Specification', 'template' => 'BIOS version', 'pc' => 'specification.bios_version', 'asset' => null,
            'synonyms' => ['bios', 'bios version', 'uefi', 'uefi version', 'firmware', 'firmware version', 'bios uefi']],
        'spec_network_adapter' => ['label' => 'Network adapter', 'group' => 'Specification', 'template' => 'Network adapter', 'pc' => 'specification.network_adapter', 'asset' => null,
            'synonyms' => ['nic', 'network adapter', 'network card', 'lan card', 'ethernet card', 'ethernet adapter', 'wifi adapter', 'wireless adapter', 'wifi card', 'network interface']],

        'building' => ['label' => 'Building', 'group' => 'Location', 'template' => 'Building', 'pc' => 'location.building', 'asset' => 'location.building',
            'synonyms' => ['building', 'bldg', 'building name', 'building code', 'bldg code', 'block', 'campus building']],
        'floor' => ['label' => 'Floor', 'group' => 'Location', 'template' => 'Floor', 'pc' => 'location.floor', 'asset' => 'location.floor',
            'synonyms' => ['floor', 'floor no', 'floor number', 'floor name', 'level', 'storey', 'story', 'flr']],
        'room' => ['label' => 'Room', 'group' => 'Location', 'template' => 'Room', 'pc' => 'location.room', 'asset' => 'location.room',
            'synonyms' => ['room', 'room name', 'room no', 'room number', 'lab', 'laboratory', 'lab name', 'office', 'office name', 'classroom', 'area', 'deployed in']],
        'room_code' => ['label' => 'Room code', 'group' => 'Location', 'template' => 'Room code', 'pc' => 'location.room_code', 'asset' => 'location.room_code',
            'synonyms' => ['room code', 'room id', 'lab code', 'location code', 'room tag']],
        'location' => ['label' => 'Location (combined)', 'group' => 'Location', 'template' => 'Location', 'pc' => 'location.location', 'asset' => 'location.location',
            'synonyms' => ['location', 'place', 'where', 'whereabouts', 'assigned location', 'deployment location', 'room location', 'location name', 'current location']],
    ];

    /**
     * Headers that could mean more than one thing, and the targets they could
     * mean. Never pre-selected.
     *
     * @var array<string, list<string>>
     */
    private const AMBIGUOUS = [
        'computer name' => ['name', 'hostname'],
        'description' => ['name', 'notes'],
        'vendor' => ['brand', 'supplier'],
        'code' => ['asset_tag', 'unit_code', 'room_code'],
        'model no' => ['model', 'model_number'],
        'model number' => ['model', 'model_number'],
    ];

    /**
     * Columns the import recognises but never saves, with the reason shown
     * under "Columns not imported" (Appendix B.2).
     *
     * @var array<string, string>
     */
    private const NEVER_IMPORTED = [
        'qr' => 'QR labels are generated in the app.',
        'qr code' => 'QR labels are generated in the app.',
        'qr identifier' => 'QR labels are generated in the app.',
        'x' => 'Floor-plan positions are set by hand on the floor plan.',
        'y' => 'Floor-plan positions are set by hand on the floor plan.',
        'position' => 'Floor-plan positions are set by hand on the floor plan.',
        'seat' => 'Floor-plan positions are set by hand on the floor plan.',
        'coordinates' => 'Floor-plan positions are set by hand on the floor plan.',
        'last maintenance' => 'Maintenance is recorded as maintenance records.',
        'repair history' => 'Maintenance is recorded as maintenance records.',
        'maintenance' => 'Maintenance is recorded as maintenance records.',
        'installed components' => 'Installed parts are recorded as component installations.',
        'id' => 'Set by the system.',
        'uuid' => 'Set by the system.',
        'created at' => 'Set by the system.',
        'updated at' => 'Set by the system.',
        'end user' => 'Custody belongs to a technician or administrator.',
        'assigned teacher' => 'Custody belongs to a technician or administrator.',
    ];

    /** Quantity columns: one row is one record; a value above 1 is reviewed. */
    public const QUANTITY_HEADERS = ['quantity', 'qty'];

    /**
     * Normalise a header (or any short label) for matching: NFKC, lower case,
     * `#` → "no", `&` → "and", "(optional)", "(required)" and `*` dropped,
     * every other non-alphanumeric a space, spaces collapsed.
     */
    public static function normalize(string $text): string
    {
        $text = Normalizer::normalize($text, Normalizer::FORM_KC) ?: $text;
        $text = mb_strtolower($text);
        $text = str_replace(['(optional)', '(required)', '*'], ' ', $text);
        $text = str_replace(['#', '&'], [' no ', ' and '], $text);
        $text = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::TARGETS);
    }

    public static function exists(string $target): bool
    {
        return isset(self::TARGETS[$target]);
    }

    /**
     * The payload key a target fills for one register, or null when the target
     * does not apply to it.
     */
    public static function payloadKey(string $target, ImportEntityType $type): ?string
    {
        $definition = self::TARGETS[$target] ?? null;

        return $definition === null ? null : ($type === ImportEntityType::PcUnit ? $definition['pc'] : $definition['asset']);
    }

    /** Whether a target can be used at all for a file holding these registers. */
    public static function appliesTo(string $target, AssetImportEntityMode $mode): bool
    {
        $definition = self::TARGETS[$target] ?? null;
        if ($definition === null) {
            return false;
        }

        return match ($mode) {
            AssetImportEntityMode::PcUnits => $definition['pc'] !== null,
            AssetImportEntityMode::Assets => $definition['asset'] !== null,
            AssetImportEntityMode::Mixed => true,
        };
    }

    public static function label(string $target): string
    {
        return self::TARGETS[$target]['label'] ?? $target;
    }

    /**
     * The fields every row of a register must have, as target keys.
     *
     * @return list<string>
     */
    public static function required(ImportEntityType $type): array
    {
        // An asset's tag is optional (2026-10-19): a register that carries tags
        // still imports them, and a row without one gets a generated tag.
        return $type === ImportEntityType::PcUnit
            ? ['unit_code', 'name']
            : ['category', 'model'];
    }

    /**
     * The template columns for a mode, in order.
     *
     * @return list<string> target keys
     */
    public static function templateTargets(AssetImportEntityMode $mode): array
    {
        $pc = ['unit_code', 'name', 'asset_tag', 'serial_number', 'hostname', 'brand', 'model', 'status', 'condition',
            'ip_address', 'mac_address', 'purchase_date', 'warranty_expiration', 'building', 'floor', 'room_code', 'notes',
            'spec_cpu', 'spec_motherboard', 'spec_ram', 'spec_gpu', 'spec_storage_primary', 'spec_storage_secondary',
            'spec_power_supply', 'spec_monitor', 'spec_keyboard', 'spec_mouse', 'spec_operating_system', 'spec_bios_version',
            'spec_network_adapter'];
        $asset = ['asset_tag', 'name', 'category', 'brand', 'model', 'model_number', 'serial_number', 'barcode', 'status',
            'condition', 'purchase_date', 'purchase_price', 'warranty_expiration', 'supplier', 'custodian_email',
            'building', 'floor', 'room_code', 'notes'];

        return match ($mode) {
            AssetImportEntityMode::PcUnits => $pc,
            AssetImportEntityMode::Assets => $asset,
            AssetImportEntityMode::Mixed => array_values(array_unique([
                'category', 'unit_code', 'asset_tag', 'name', 'serial_number', 'hostname', 'brand', 'model', 'model_number',
                'barcode', 'status', 'condition', 'ip_address', 'mac_address', 'purchase_date', 'purchase_price',
                'warranty_expiration', 'supplier', 'custodian_email', 'building', 'floor', 'room_code', 'notes',
                ...array_slice($pc, 17),
            ])),
        };
    }

    public static function templateHeader(string $target): string
    {
        return self::TARGETS[$target]['template'];
    }

    /**
     * Match one header for a mode. `confidence` is exact, synonym, likely,
     * ambiguous or none.
     *
     * @return array{target: string|null, confidence: string, alternatives: list<string>, never_imported: string|null}
     */
    public static function match(string $header, AssetImportEntityMode $mode): array
    {
        $normalized = self::normalize($header);
        $result = fn (?string $target, string $confidence, array $alternatives = [], ?string $never = null): array => [
            'target' => $target, 'confidence' => $confidence, 'alternatives' => array_values($alternatives), 'never_imported' => $never,
        ];

        if ($normalized === '') {
            return $result(null, 'none');
        }

        $applicable = array_filter(self::TARGETS, fn (array $d, string $key): bool => self::appliesTo($key, $mode), ARRAY_FILTER_USE_BOTH);

        foreach ($applicable as $key => $definition) {
            if (self::normalize($definition['template']) === $normalized) {
                return $result($key, 'exact');
            }
        }

        if (isset(self::AMBIGUOUS[$normalized])) {
            $choices = array_values(array_filter(self::AMBIGUOUS[$normalized], fn (string $t): bool => isset($applicable[$t])));
            if (count($choices) === 1) {
                return $result($choices[0], 'likely');
            }
            if ($choices !== []) {
                return $result(null, 'ambiguous', $choices);
            }
        }

        foreach ($applicable as $key => $definition) {
            if (in_array($normalized, $definition['synonyms'], true)) {
                return $result($key, 'synonym');
            }
        }

        foreach ($applicable as $key => $definition) {
            if (in_array($normalized, $definition['likely'] ?? [], true)) {
                return $result($key, 'likely');
            }
        }

        if (isset(self::NEVER_IMPORTED[$normalized])) {
            return $result(null, 'none', [], self::NEVER_IMPORTED[$normalized]);
        }

        // A header that contains a multi-word synonym as whole words
        // ("PC Serial No." ⊃ "serial no"): the longest such synonym wins, and
        // two targets tied on length make it ambiguous.
        $padded = ' '.$normalized.' ';
        $best = [];
        $bestLength = 0;
        foreach ($applicable as $key => $definition) {
            foreach ($definition['synonyms'] as $synonym) {
                if (! str_contains($synonym, ' ') || ! str_contains($padded, ' '.$synonym.' ')) {
                    continue;
                }
                $length = strlen($synonym);
                if ($length > $bestLength) {
                    [$best, $bestLength] = [[$key], $length];
                } elseif ($length === $bestLength && ! in_array($key, $best, true)) {
                    $best[] = $key;
                }
            }
        }
        if (count($best) === 1) {
            return $result($best[0], 'likely');
        }
        if (count($best) > 1) {
            return $result(null, 'ambiguous', $best);
        }

        // A typo away from exactly one synonym.
        if (strlen($normalized) >= 5) {
            $near = [];
            foreach ($applicable as $key => $definition) {
                foreach ($definition['synonyms'] as $synonym) {
                    if (strlen($synonym) >= 5 && levenshtein($normalized, $synonym) <= 2) {
                        $near[$key] = true;
                    }
                }
            }
            if (count($near) === 1) {
                return $result(array_key_first($near), 'likely');
            }
        }

        return $result(null, 'none');
    }

    /**
     * The table the mapping screen renders its selects from.
     *
     * @return list<array{key: string, label: string, group: string, destination: string, hint: string|null, required: bool, payload: array{pc_unit: string|null, asset: string|null}}>
     */
    public static function forClient(AssetImportEntityMode $mode): array
    {
        $required = match ($mode) {
            AssetImportEntityMode::PcUnits => self::required(ImportEntityType::PcUnit),
            AssetImportEntityMode::Assets => self::required(ImportEntityType::Asset),
            AssetImportEntityMode::Mixed => ['category'],
        };

        $out = [];
        foreach (self::TARGETS as $key => $definition) {
            if (! self::appliesTo($key, $mode)) {
                continue;
            }
            $out[] = [
                'key' => $key,
                'label' => $definition['label'],
                'group' => $definition['group'],
                'destination' => self::destination($definition, $mode),
                'hint' => $definition['hint'] ?? null,
                'required' => in_array($key, $required, true),
                // The saved value each register reads this target from.
                'payload' => ['pc_unit' => $definition['pc'], 'asset' => $definition['asset']],
            ];
        }

        return $out;
    }

    /**
     * Where a target lands, in words ("PC unit › Specification › RAM").
     *
     * Only the registers this kind of file holds are named.
     *
     * @param  array{label: string, group: string, pc: string|null, asset: string|null}  $definition
     */
    private static function destination(array $definition, AssetImportEntityMode $mode): string
    {
        if ($mode === AssetImportEntityMode::PcUnits) {
            $definition['asset'] = null;
        } elseif ($mode === AssetImportEntityMode::Assets) {
            $definition['pc'] = null;
        }

        if ($definition['group'] === 'Location') {
            return 'Matched to an existing room';
        }
        if (str_starts_with((string) $definition['asset'], 'catalog.')) {
            $asset = 'Asset › Catalog › '.$definition['label'];

            return $definition['pc'] !== null ? 'PC unit › '.$definition['label'].' · '.$asset : $asset;
        }

        $parts = [];
        if ($definition['pc'] !== null) {
            $parts[] = 'PC unit › '.($definition['group'] === 'Specification' ? 'Specification › ' : '').$definition['label'];
        }
        if ($definition['asset'] !== null) {
            $parts[] = 'Asset › '.$definition['label'];
        }

        return implode(' · ', $parts);
    }
}
