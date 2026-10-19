<?php

declare(strict_types=1);

use App\Enums\AssetImportStatus;
use App\Models\AssetImport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\Support\AssetImportFixtures;

/*
 * Phase 2.10 R6 — what an import will and will not read (SDD DD-96).
 *
 * Accepted: a real .xlsx or CSV. Refused at upload: other formats, a file
 * whose bytes contradict its name, macros, embedded or active content,
 * external links, and zip bombs. Read faithfully: header rows below a title,
 * merged cells, cached formula results (never a formula), date-formatted
 * cells (in the workbook's own calendar), CSV in the allow-listed encodings
 * and delimiters — and refused when longer or wider than the caps.
 */

beforeEach(function (): void {
    seedRbac();
    Storage::fake('local');
    $this->admin = userWithRole('administrator');
    $this->upload = fn ($file, string $mode = 'pc_units') => $this->actingAs($this->admin)
        ->post('/api/admin/asset-imports', ['file' => $file, 'entity_mode' => $mode], ['Accept' => 'application/json']);
});

/* ---------------------------------------------------------------- refusals */

it('refuses files that are not .xlsx or CSV, or whose bytes contradict their name', function (string $name, string $bytes): void {
    ($this->upload)(AssetImportFixtures::file($bytes, $name))->assertUnprocessable()->assertJsonValidationErrors('file');
    expect(AssetImport::query()->count())->toBe(0);
})->with([
    'a PNG renamed .csv' => ['inventory.csv', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==')],
    'an old binary .xls' => ['inventory.xls', "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\0", 512)],
    'a PDF' => ['inventory.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF"],
]);

it('refuses a CSV named .xlsx and an .xlsx named .csv', function (): void {
    ($this->upload)(AssetImportFixtures::file("Unit code,Name\nPC-1,One\n", 'inventory.xlsx'))->assertUnprocessable();

    $xlsx = AssetImportFixtures::xlsxRows([['Unit code', 'Name'], ['PC-1', 'One']]);
    ($this->upload)(new UploadedFile($xlsx->getRealPath(), 'inventory.csv', null, null, true))->assertUnprocessable();
});

it('refuses macros, embedded objects, controls and links to other workbooks', function (string $part): void {
    ($this->upload)(AssetImportFixtures::xlsxWithParts([$part => 'x']))->assertUnprocessable()->assertJsonValidationErrors('file');
})->with(['xl/vbaProject.bin', 'xl/externalLinks/externalLink1.xml', 'xl/embeddings/oleObject1.bin', 'xl/activeX/activeX1.xml', 'xl/ctrlProps/ctrlProp1.xml']);

it('refuses a workbook that expands far beyond its size', function (): void {
    ($this->upload)(AssetImportFixtures::xlsxWithParts(['docProps/padding.xml' => str_repeat('A', 30 * 1024 * 1024)]))
        ->assertUnprocessable()->assertJsonValidationErrors('file');
});

it('marks a workbook with an XML entity as unreadable, not an error', function (): void {
    $file = AssetImportFixtures::xlsxRows([['Unit code', 'Name'], ['PC-1', 'One']]);
    $zip = new ZipArchive;
    $zip->open($file->getRealPath());
    $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->addFromString('xl/worksheets/sheet1.xml', str_replace('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]>', $sheet));
    $zip->close();

    ($this->upload)(new UploadedFile($file->getRealPath(), 'inventory.xlsx', null, null, true))->assertCreated();

    $import = AssetImport::query()->firstOrFail();
    expect($import->status)->toBe(AssetImportStatus::Unreadable)
        ->and($import->error_code)->toBe('file_unreadable');
});

it('refuses a sheet with more rows than the cap', function (): void {
    config(['assets.import.max_rows' => 3]);
    $rows = [['Unit code', 'Name']];
    for ($i = 1; $i <= 4; $i++) {
        $rows[] = ["PC-{$i}", "PC {$i}"];
    }
    ($this->upload)(AssetImportFixtures::csv($rows))->assertCreated();

    expect(AssetImport::query()->firstOrFail())
        ->status->toBe(AssetImportStatus::Unreadable)
        ->error_code->toBe('file_too_long');
});

it('refuses a sheet wider than the cap', function (): void {
    config(['assets.import.max_columns' => 3]);
    ($this->upload)(AssetImportFixtures::csv([['A', 'B', 'C', 'D'], ['1', '2', '3', '4']]))->assertCreated();

    expect(AssetImport::query()->firstOrFail()->error_code)->toBe('file_too_wide');
});

it('points out a second upload of the same file', function (): void {
    $bytes = "Unit code,Name\nPC-1,One\n";
    ($this->upload)(AssetImportFixtures::file($bytes, 'a.csv'))->assertCreated();
    $second = ($this->upload)(AssetImportFixtures::file($bytes, 'b.csv'))->assertCreated();

    expect($second->json('data.duplicate_of.name'))->toBe('a.csv');
});

/* ------------------------------------------------------------------ reading */

it('reads merged cells, cached formula results and date cells as values', function (): void {
    $file = AssetImportFixtures::xlsx(function (Worksheet $sheet): void {
        $sheet->fromArray([['Unit code', 'Name', 'Room code', 'Purchase date'], ['PC-1', 'One', 'LAB-1', null], ['PC-2', 'Two', null, null]]);
        $sheet->mergeCells('C2:C3');
        // Explicitly a formula: the app's SafeValueBinder (set globally once any
        // export has run) would otherwise write it as the text it starts with.
        $sheet->setCellValueExplicit('B3', '=UPPER("two")', DataType::TYPE_FORMULA);
        $sheet->setCellValue('D2', Date::PHPToExcel(new DateTime('2024-03-31')));
        $sheet->getStyle('D2')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_YYYYMMDD);
    });

    ($this->upload)($file)->assertCreated();
    $rows = AssetImport::query()->firstOrFail()->rows()->orderBy('row_number')->get();

    expect($rows[1]->raw['2'])->toBe('LAB-1')
        ->and($rows[1]->cell_flags['2'])->toContain('merged_fill')
        ->and($rows[1]->raw['1'])->toBe('TWO')
        ->and($rows[1]->cell_flags['1'])->toContain('formula')
        ->and($rows[0]->raw['3'])->toBe('2024-03-31')
        ->and($rows[0]->cell_flags['3'])->toContain('date_cell');
});

it('reads dates in a 1904-calendar workbook, and does not leak the calendar to the next file', function (): void {
    // PhpSpreadsheet 1.30 writes `date1904` from its process-wide calendar.
    Date::setExcelCalendar(Date::CALENDAR_MAC_1904);
    $file = AssetImportFixtures::xlsx(function (Worksheet $sheet): void {
        $sheet->fromArray([['Unit code', 'Name', 'Purchase date'], ['PC-1', 'One', null]]);
        $sheet->setCellValue('C2', 43555 - 1462); // 2019-03-31 in the 1904 system
        $sheet->getStyle('C2')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_YYYYMMDD);
    });
    Date::setExcelCalendar(Date::CALENDAR_WINDOWS_1900);
    ($this->upload)($file)->assertCreated();

    expect(AssetImport::query()->firstOrFail()->rows()->first()->raw['2'])->toBe('2019-03-31')
        ->and(Date::getExcelCalendar())->toBe(Date::CALENDAR_WINDOWS_1900);
});

it('reads CSV in UTF-16 with a byte-order mark, and in Windows-1252', function (string $encoding, string $bom): void {
    ($this->upload)(AssetImportFixtures::csv([['Unit code', 'Name'], ['PC-1', 'Café Lab']], encoding: $encoding, prefix: $bom))->assertCreated();

    $import = AssetImport::query()->firstOrFail();
    expect($import->source_meta['encoding'])->toBe($encoding)
        ->and($import->rows()->first()->raw['1'])->toBe('Café Lab');
})->with([
    'UTF-16LE' => ['UTF-16LE', "\xFF\xFE"],
    'Windows-1252' => ['Windows-1252', ''],
]);

it('infers a semicolon delimiter and keeps a backslash an ordinary character', function (): void {
    ($this->upload)(AssetImportFixtures::file("Unit code;Name;Notes\nPC-1;One;\"C:\\\\temp\\\\\"\n", 'inventory.csv'))->assertCreated();

    $import = AssetImport::query()->firstOrFail();
    expect($import->source_meta['delimiter'])->toBe(';')
        ->and($import->rows()->first()->raw['2'])->toBe('C:\\\\temp\\\\');
});

it('reads again with another header row when asked', function (): void {
    ($this->upload)(AssetImportFixtures::csv([['Inventory'], ['Unit code', 'Name'], ['PC-1', 'One']]))->assertCreated();
    $import = AssetImport::query()->firstOrFail();
    expect($import->source_meta['header_row'])->toBe(2);

    $this->actingAs($this->admin)->putJson("/api/admin/asset-imports/{$import->uuid}/source", ['header_row' => 1])->assertOk();

    expect($import->refresh()->source_meta['header_row'])->toBe(1)
        ->and($import->total_rows)->toBe(2)
        ->and($import->revision)->toBeGreaterThan(2);
});
