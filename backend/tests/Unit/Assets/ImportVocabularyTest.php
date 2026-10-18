<?php

declare(strict_types=1);

use App\Domains\Assets\Services\Import\ImportColumnMapper;
use App\Domains\Assets\Services\Import\ImportDateParser;
use App\Domains\Assets\Services\Import\ImportFieldDictionary;
use App\Domains\Assets\Services\Import\ImportValueMapper;
use App\Enums\AssetImportEntityMode;
use App\Enums\ImportDateFormat;

/*
 * Phase 2.10 R6 — the import's vocabulary (plan Appendix B): header
 * synonyms and confidence, value meanings, and strict dates.
 */

/* ------------------------------------------------------------------ headers */

it('matches headers with the confidence the plan prescribes', function (string $header, string $mode, ?string $target, string $confidence): void {
    $match = ImportFieldDictionary::match($header, AssetImportEntityMode::from($mode));

    expect($match['target'])->toBe($target)->and($match['confidence'])->toBe($confidence);
})->with([
    'template header' => ['Unit code', 'pc_units', 'unit_code', 'exact'],
    'synonym, punctuation' => ['S/N', 'pc_units', 'serial_number', 'synonym'],
    'number sign' => ['Asset #', 'assets', 'asset_tag', 'synonym'],
    'optional marker dropped' => ['Hostname (optional)', 'pc_units', 'hostname', 'exact'],
    'contains a multi-word synonym' => ['PC Serial No.', 'pc_units', 'serial_number', 'likely'],
    'a typo away' => ['Warranty Expirey', 'pc_units', 'warranty_expiration', 'likely'],
    'likely word: cpu' => ['CPU', 'pc_units', 'spec_cpu', 'likely'],
    'ambiguous: description' => ['Description', 'assets', null, 'ambiguous'],
    'ambiguous: vendor' => ['Vendor', 'assets', null, 'ambiguous'],
    'ambiguous: computer name' => ['Computer name', 'pc_units', null, 'ambiguous'],
    'one meaning left in this mode' => ['Vendor', 'pc_units', 'brand', 'likely'],
    'not for this register' => ['Barcode', 'pc_units', null, 'none'],
    'unknown' => ['Colour', 'pc_units', null, 'none'],
]);

it('names the columns it recognises but never imports', function (): void {
    expect(ImportFieldDictionary::match('QR code', AssetImportEntityMode::PcUnits)['never_imported'])->toContain('QR labels')
        ->and(ImportFieldDictionary::match('Seat', AssetImportEntityMode::PcUnits)['never_imported'])->toContain('floor plan');
});

it('gives one target to one column — the more confident keeps it', function (): void {
    $suggestion = (new ImportColumnMapper)->suggest([
        ['index' => 0, 'letter' => 'A', 'header' => 'Serial', 'samples' => []],
        ['index' => 1, 'letter' => 'B', 'header' => 'Serial number', 'samples' => []],
    ], AssetImportEntityMode::PcUnits);

    expect($suggestion['columns'][1]['target'])->toBe('serial_number')
        ->and($suggestion['columns'][0]['target'])->toBeNull()
        ->and($suggestion['columns'][0]['confidence'])->toBe('ambiguous');
});

it('recognises a file built from the template', function (AssetImportEntityMode $mode): void {
    $headers = array_map(fn (string $t, int $i): array => ['index' => $i, 'letter' => 'A', 'header' => ImportFieldDictionary::templateHeader($t), 'samples' => []],
        ImportFieldDictionary::templateTargets($mode), array_keys(ImportFieldDictionary::templateTargets($mode)));

    expect((new ImportColumnMapper)->suggest($headers, $mode)['template_match'])->toBeTrue();
})->with(AssetImportEntityMode::cases());

/* ----------------------------------------------------------------- meanings */

it('proposes value meanings from values, labels and synonyms', function (string $field, string $raw, string $mode, ?string $meaning, string $confidence): void {
    $suggestion = ImportValueMapper::suggest($field, $raw, AssetImportEntityMode::from($mode));

    expect($suggestion['value'])->toBe($meaning)->and($suggestion['confidence'])->toBe($confidence);
})->with([
    'the app\'s own label' => ['status.asset', 'In Service', 'assets', 'deployed', 'exact'],
    'stored value' => ['status.asset', 'in_stock', 'assets', 'in_stock', 'exact'],
    'synonym' => ['status.asset', 'Condemned', 'assets', 'disposed', 'synonym'],
    'likely' => ['status.asset', 'Working', 'assets', 'deployed', 'likely'],
    'pc status' => ['status.pc_unit', 'in use', 'pc_units', 'assigned', 'synonym'],
    'condition' => ['condition', 'BER', 'assets', 'decommissioned', 'synonym'],
    'category synonym' => ['category', 'Photocopier', 'assets', 'asset:printer', 'synonym'],
    'computer in a mixed file' => ['category', 'Desktop', 'mixed', 'pc_unit', 'likely'],
    'CPU is never guessed' => ['category', 'CPU', 'mixed', null, 'ambiguous'],
    'AVR is never guessed' => ['category', 'AVR', 'assets', null, 'ambiguous'],
    'system unit in a mixed file' => ['category', 'System Unit', 'mixed', null, 'ambiguous'],
    'system unit in an asset file' => ['category', 'System Unit', 'assets', 'asset:system_unit', 'exact'],
    'unknown' => ['status.asset', 'Workng', 'assets', null, 'none'],
]);

/* -------------------------------------------------------------------- dates */

it('reads dates strictly', function (string $raw, ?ImportDateFormat $order, ?string $value, ?string $issue): void {
    $parsed = ImportDateParser::parse($raw, [], $order);

    expect($parsed['value'])->toBe($value)->and($parsed['issue'])->toBe($issue);
})->with([
    'iso' => ['2024-03-31', null, '2024-03-31', null],
    'slashes, year first' => ['2024/3/5', null, '2024-03-05', null],
    'day first, chosen' => ['05/03/2024', ImportDateFormat::DayFirst, '2024-03-05', null],
    'month first, chosen' => ['05/03/2024', ImportDateFormat::MonthFirst, '2024-05-03', null],
    'ambiguous, no order' => ['05/03/2024', null, null, 'date_ambiguous'],
    'unambiguous on its own' => ['25/12/2023', null, '2023-12-25', null],
    'not a real date' => ['31/02/2024', ImportDateFormat::DayFirst, null, 'date_unparseable'],
    'month-first contradiction' => ['13/05/2024', ImportDateFormat::MonthFirst, null, 'date_unparseable'],
    'month name' => ['5 Jan 2024', null, '2024-01-05', null],
    'month name first' => ['January 5, 2024', null, '2024-01-05', null],
    'excel style' => ['05-Jan-2024', null, '2024-01-05', null],
    'year only' => ['2024', null, null, 'date_unparseable'],
    'excel serial, reviewed' => ['45382', null, '2024-03-31', 'date_from_serial'],
    'free text' => ['next week', null, null, 'date_unparseable'],
]);

it('accepts a date cell as already read', function (): void {
    expect(ImportDateParser::parse('2024-03-31', ['date_cell'], null))->toMatchArray(['value' => '2024-03-31', 'issue' => null]);
});
