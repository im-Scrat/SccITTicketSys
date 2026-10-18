<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\AssetImport;
use App\Models\Building;
use App\Models\Floor;
use App\Models\Room;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use ZipArchive;

/**
 * Builders for the asset-import tests (Phase 2.10 R6): real spreadsheet files
 * on disk (so the MIME check reads real bytes), a small estate to match
 * locations against, and the upload → map → confirm steps through the API.
 */
final class AssetImportFixtures
{
    /**
     * A CSV upload.
     *
     * @param  list<list<string>>  $rows
     */
    public static function csv(array $rows, string $name = 'inventory.csv', string $delimiter = ',', ?string $encoding = null, string $prefix = ''): UploadedFile
    {
        $handle = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($handle, $row, $delimiter, '"', '');
        }
        rewind($handle);
        $text = (string) stream_get_contents($handle);
        fclose($handle);

        $bytes = $encoding === null ? $text : mb_convert_encoding($text, $encoding, 'UTF-8');

        return self::file($prefix.$bytes, $name);
    }

    /**
     * An .xlsx upload, built with PhpSpreadsheet.
     *
     * @param  callable(Worksheet, Spreadsheet): void  $build
     */
    public static function xlsx(callable $build, string $name = 'inventory.xlsx'): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $build($spreadsheet->getActiveSheet(), $spreadsheet);
        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile($path, $name, null, null, true);
    }

    /**
     * An .xlsx built from rows (first row = headers).
     *
     * @param  list<list<string|int|float|null>>  $rows
     */
    public static function xlsxRows(array $rows, string $name = 'inventory.xlsx'): UploadedFile
    {
        return self::xlsx(fn (Worksheet $sheet) => $sheet->fromArray($rows, null, 'A1', true), $name);
    }

    /**
     * A real .xlsx with extra parts added to its zip (macros, links…).
     *
     * @param  array<string, string>  $entries
     */
    public static function xlsxWithParts(array $entries, string $name = 'inventory.xlsx'): UploadedFile
    {
        $file = self::xlsxRows([['Unit code', 'Name'], ['PC-01', 'Lab PC 1']]);
        $zip = new ZipArchive;
        $zip->open($file->getRealPath());
        foreach ($entries as $entry => $content) {
            $zip->addFromString($entry, $content);
        }
        $zip->close();

        return new UploadedFile($file->getRealPath(), $name, null, null, true);
    }

    public static function file(string $bytes, string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'imp');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, null, null, true);
    }

    /**
     * Main Building › 1st Floor › LAB-1 "Computer Lab 1", LAB-2 "Computer Lab
     * 2", and OLD-1 "Old Lab" (not in use).
     *
     * @return array{building: Building, floor: Floor, lab1: Room, lab2: Room, old: Room}
     */
    public static function estate(): array
    {
        $building = Building::factory()->create(['code' => 'MAIN', 'name' => 'Main Building', 'is_active' => true]);
        $floor = Floor::factory()->create(['building_id' => $building->id, 'floor_number' => 1, 'name' => '1st Floor']);

        return [
            'building' => $building,
            'floor' => $floor,
            'lab1' => Room::factory()->create(['floor_id' => $floor->id, 'code' => 'LAB-1', 'name' => 'Computer Lab 1', 'is_active' => true]),
            'lab2' => Room::factory()->create(['floor_id' => $floor->id, 'code' => 'LAB-2', 'name' => 'Computer Lab 2', 'is_active' => true]),
            'old' => Room::factory()->create(['floor_id' => $floor->id, 'code' => 'OLD-1', 'name' => 'Old Lab', 'is_active' => false]),
        ];
    }

    /** Upload through the API and return the import as stored. */
    public static function upload(User $actor, UploadedFile $file, string $mode = 'pc_units'): AssetImport
    {
        test()->actingAs($actor)->post('/api/admin/asset-imports', ['file' => $file, 'entity_mode' => $mode], ['Accept' => 'application/json'])->assertCreated();

        return AssetImport::query()->latest('id')->firstOrFail();
    }

    /**
     * Save a mapping through the API (the proposed one, with overrides).
     *
     * @param  array<string, string|null>  $overrides  column index => target
     * @param  array<string, mixed>  $extra
     */
    public static function map(User $actor, AssetImport $import, array $overrides = [], array $extra = []): TestResponse
    {
        $import->refresh();
        $mapping = array_replace(array_map('strval', $import->mapping ?? []), $overrides);

        return test()->actingAs($actor)->putJson("/api/admin/asset-imports/{$import->uuid}/mapping", [
            'mapping' => (object) $mapping,
            'value_mappings' => (object) ($import->value_mappings ?? []),
            ...$extra,
        ]);
    }

    /** Confirm with the plan the API shows now. */
    public static function confirm(User $actor, AssetImport $import): TestResponse
    {
        $plan = test()->actingAs($actor)->getJson("/api/admin/asset-imports/{$import->uuid}")->json('data.plan');

        return test()->actingAs($actor)->postJson("/api/admin/asset-imports/{$import->uuid}/confirm", [
            'revision' => $plan['revision'],
            'expected' => ['pc_units' => $plan['pc_units'], 'assets' => $plan['assets'], 'catalog_entries' => count($plan['catalog_entries'])],
        ]);
    }
}
