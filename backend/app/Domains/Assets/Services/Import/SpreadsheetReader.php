<?php

declare(strict_types=1);

namespace App\Domains\Assets\Services\Import;

use App\Domains\Assets\Exceptions\ImportFileRejected;
use App\Enums\AssetImportEntityMode;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;
use ZipArchive;

/**
 * Reads an inventory spreadsheet into header and data rows (Phase 2.10 R6,
 * SDD DD-96). Nothing here decides what a cell *means* — that is the mapper's
 * and the row builder's job; this produces text and says how it got it.
 *
 * **Excel (.xlsx)** is read in one full load of the chosen sheet: an import is
 * capped at 5,000 rows, and one load is far cheaper than chunked re-parses.
 * Before loading, every worksheet part and the shared-strings part are size-
 * checked inside the zip, so a pathological sheet is refused without being
 * parsed. Formats are read (`setReadDataOnly(false)`) because merged cells and
 * date formats need them. Formulas contribute their **cached** value only —
 * nothing is calculated and nothing is stored as a formula. A number is a date
 * only when its cell is formatted as one. Merged cells fill every cell they
 * cover. The 1900/1904 calendar a workbook sets is reset after every load, so
 * it cannot leak into the next one in the same worker.
 *
 * **CSV** has no declared encoding: a byte-order mark decides it, else valid
 * UTF-8, else Windows-1252 — and only allow-listed encodings are accepted at
 * all. The delimiter is inferred unless chosen. Fields are read with
 * `fgetcsv(…, '"', '')`, so a backslash is an ordinary character.
 *
 * Cell flags carried to the row builder: `date_cell`, `number`, `formula`,
 * `formula_without_value`, `cell_error`, `merged_fill`.
 */
final class SpreadsheetReader
{
    public const ENCODINGS = ['UTF-8', 'UTF-16LE', 'UTF-16BE', 'Windows-1252', 'ISO-8859-1'];

    public const DELIMITERS = [',', ';', "\t", '|'];

    private const EXCEL_ERRORS = ['#NULL!', '#DIV/0!', '#VALUE!', '#REF!', '#NAME?', '#NUM!', '#N/A', '#GETTING_DATA', '#SPILL!', '#CALC!'];

    public function __construct(private readonly HeaderRowDetector $detector) {}

    /**
     * @param  array{sheet?: string|null, header_row?: int|null, delimiter?: string|null, encoding?: string|null}  $options
     * @return array{
     *     meta: array{sheets: list<string>, sheet: string|null, encoding: string|null, delimiter: string|null, header_row: int, columns: int},
     *     headers: list<array{index: int, letter: string, header: string, samples: list<string>}>,
     *     rows: list<array{row_number: int, cells: array<array-key, string>, flags: array<array-key, list<string>>}>
     * }
     */
    public function read(string $path, string $extension, AssetImportEntityMode $mode, array $options = []): array
    {
        [$grid, $flags, $meta] = strtolower($extension) === 'xlsx'
            ? $this->readXlsx($path, $options)
            : $this->readCsv($path, $options);

        $limits = config('assets.import');
        $width = 0;
        foreach ($grid as $cells) {
            $width = max($width, $cells === [] ? 0 : max(array_keys($cells)) + 1);
        }
        if ($width > (int) $limits['max_columns']) {
            throw new ImportFileRejected('file_too_wide', sprintf('This sheet has %d columns; at most %d can be imported. Remove the columns you do not need and upload it again.', $width, (int) $limits['max_columns']));
        }
        if ($grid === []) {
            throw new ImportFileRejected('file_empty', 'This sheet has no rows to import.');
        }

        $requested = isset($options['header_row']) ? (int) $options['header_row'] : null;
        $headerRow = $requested !== null && isset($grid[$requested])
            ? $requested
            : (int) $this->detector->detect($grid, $mode);

        $headerCells = $grid[$headerRow] ?? [];
        $data = [];
        foreach ($grid as $number => $cells) {
            if ($number <= $headerRow || array_filter($cells, fn (string $v): bool => trim($v) !== '') === []) {
                continue;
            }
            $data[$number] = $cells;
        }

        if (count($data) > (int) $limits['max_rows']) {
            throw new ImportFileRejected('file_too_long', sprintf('This sheet has %s rows; at most %s can be imported at once. Split it into smaller files.', number_format(count($data)), number_format((int) $limits['max_rows'])));
        }

        $headers = [];
        for ($i = 0; $i < $width; $i++) {
            $samples = [];
            $hasData = false;
            foreach ($data as $cells) {
                $value = trim($cells[$i] ?? '');
                if ($value === '') {
                    continue;
                }
                $hasData = true;
                if (count($samples) < 3) {
                    $samples[] = mb_substr($value, 0, 120);
                } else {
                    break;
                }
            }
            $header = trim($headerCells[$i] ?? '');
            if ($header === '' && ! $hasData) {
                continue;
            }
            $letter = Coordinate::stringFromColumnIndex($i + 1);
            $headers[] = ['index' => $i, 'letter' => $letter, 'header' => $header !== '' ? mb_substr($header, 0, 255) : "Column {$letter}", 'samples' => $samples];
        }

        $kept = array_column($headers, 'index');
        $rows = [];
        foreach ($data as $number => $cells) {
            $out = [];
            $outFlags = [];
            foreach ($kept as $i) {
                $value = $cells[$i] ?? '';
                if ($value !== '') {
                    $out[(string) $i] = $value;
                }
                if (isset($flags[$number][$i])) {
                    $outFlags[(string) $i] = array_values(array_unique($flags[$number][$i]));
                }
            }
            $rows[] = ['row_number' => $number, 'cells' => $out, 'flags' => $outFlags];
        }

        return [
            'meta' => [...$meta, 'header_row' => $headerRow, 'columns' => count($headers)],
            'headers' => $headers,
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{0: array<int, array<int, string>>, 1: array<int, array<int, list<string>>>, 2: array{sheets: list<string>, sheet: string|null, encoding: string|null, delimiter: string|null}}
     */
    private function readXlsx(string $path, array $options): array
    {
        $limits = config('assets.import');
        $reader = new Xlsx;
        $reader->setReadDataOnly(false);
        $reader->setReadEmptyCells(false);
        $reader->setIncludeCharts(false);
        $reader->setAllowExternalImages(false);

        try {
            $info = $reader->listWorksheetInfo($path);
        } catch (Throwable) {
            throw ImportFileRejected::unreadable();
        }
        $sheets = array_values(array_map(fn (array $s): string => (string) $s['worksheetName'], $info));
        if ($sheets === []) {
            throw new ImportFileRejected('file_empty', 'This workbook has no sheets.');
        }

        $sheet = isset($options['sheet']) && in_array($options['sheet'], $sheets, true) ? (string) $options['sheet'] : $sheets[0];
        $sheetInfo = collect($info)->firstWhere('worksheetName', $sheet);
        // A generous bound before loading: the used range can run past the data.
        if ((int) ($sheetInfo['totalRows'] ?? 0) > (int) $limits['max_rows'] * 2 + 50) {
            throw new ImportFileRejected('file_too_long', sprintf('This sheet has about %s rows; at most %s can be imported at once. Split it into smaller files.', number_format((int) $sheetInfo['totalRows']), number_format((int) $limits['max_rows'])));
        }

        $this->checkPartSizes($path, (int) $limits['max_part_mb']);
        $reader->setLoadSheetsOnly([$sheet]);

        $spreadsheet = null;
        try {
            $spreadsheet = $reader->load($path);
            $worksheet = $spreadsheet->getSheetByName($sheet) ?? $spreadsheet->getSheet(0);
            [$grid, $flags] = $this->gridFrom($worksheet);
        } catch (ImportFileRejected $e) {
            throw $e;
        } catch (Throwable) {
            throw ImportFileRejected::unreadable();
        } finally {
            if ($spreadsheet instanceof Spreadsheet) {
                $spreadsheet->disconnectWorksheets();
            }
            Date::setExcelCalendar(Date::CALENDAR_WINDOWS_1900);
        }

        return [$grid, $flags, ['sheets' => $sheets, 'sheet' => $sheet, 'encoding' => null, 'delimiter' => null]];
    }

    private function checkPartSizes(string $path, int $maxMb): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw ImportFileRejected::unreadable();
        }
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = strtolower((string) ($stat['name'] ?? ''));
                if ((str_starts_with($name, 'xl/worksheets/') || $name === 'xl/sharedstrings.xml') && (int) $stat['size'] > $maxMb * 1024 * 1024) {
                    throw new ImportFileRejected('file_too_complex', 'One sheet in this workbook is too large to read. Split it into smaller files.');
                }
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * @return array{0: array<int, array<int, string>>, 1: array<int, array<int, list<string>>>}
     */
    private function gridFrom(Worksheet $worksheet): array
    {
        $grid = [];
        $flags = [];
        $lastRow = $worksheet->getHighestDataRow();
        $lastColumn = Coordinate::columnIndexFromString($worksheet->getHighestDataColumn());
        $maxColumns = (int) config('assets.import.max_columns');

        if ($lastColumn > $maxColumns) {
            throw new ImportFileRejected('file_too_wide', sprintf('This sheet has %d columns; at most %d can be imported. Remove the columns you do not need and upload it again.', $lastColumn, $maxColumns));
        }

        // Only the cells that exist: asking for every coordinate would create
        // an empty cell object for each one.
        foreach ($worksheet->getCoordinates(false) as $coordinate) {
            [$col, $row] = Coordinate::indexesFromString($coordinate);
            if ($row > $lastRow || $col > $lastColumn) {
                continue;
            }
            $cell = $worksheet->getCell($coordinate);
            [$value, $cellFlags] = $this->cellText($cell);
            if ($value !== '') {
                $grid[$row][$col - 1] = $value;
            }
            if ($cellFlags !== []) {
                $flags[$row][$col - 1] = $cellFlags;
            }
        }
        for ($row = 1; $row <= $lastRow; $row++) {
            $grid[$row] ??= [];
        }

        // A merged range fills every cell it covers with its top-left value —
        // a location written once beside a block of rows applies to each.
        foreach ($worksheet->getMergeCells() as $range) {
            [[$c1, $r1], [$c2, $r2]] = Coordinate::rangeBoundaries($range);
            $value = $grid[$r1][$c1 - 1] ?? '';
            if ($value === '') {
                continue;
            }
            for ($r = $r1; $r <= min($r2, $lastRow); $r++) {
                for ($c = $c1; $c <= min($c2, $lastColumn); $c++) {
                    if ($r === $r1 && $c === $c1) {
                        continue;
                    }
                    $grid[$r][$c - 1] = $value;
                    $flags[$r][$c - 1][] = 'merged_fill';
                }
            }
        }

        foreach ($grid as &$cells) {
            ksort($cells);
        }
        unset($cells);
        ksort($grid);

        return [$grid, $flags];
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private function cellText(Cell $cell): array
    {
        $value = $cell->getValue();
        $flags = [];

        if ($value === null || $value === '') {
            return ['', []];
        }

        if ($cell->isFormula()) {
            $flags[] = 'formula';
            $value = $cell->getOldCalculatedValue();
            if ($value === null || $value === '') {
                return ['', ['formula', 'formula_without_value']];
            }
        }

        if ($value instanceof RichText) {
            $value = $value->getPlainText();
        }

        if (is_string($value) && in_array(strtoupper(trim($value)), self::EXCEL_ERRORS, true)) {
            return ['', [...$flags, 'cell_error']];
        }

        if (is_bool($value)) {
            return [$value ? 'TRUE' : 'FALSE', $flags];
        }

        if (is_int($value) || is_float($value)) {
            if (Date::isDateTime($cell, $value)) {
                try {
                    return [Date::excelToDateTimeObject($value)->format('Y-m-d'), [...$flags, 'date_cell']];
                } catch (Throwable) {
                    return ['', [...$flags, 'cell_error']];
                }
            }

            return [$this->numberText($value), [...$flags, 'number']];
        }

        return [trim((string) $value), $flags];
    }

    /** An integral number without exponent or trailing ".0"; others as written. */
    private function numberText(int|float $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (floor($value) === $value && abs($value) < 1e15) {
            return sprintf('%.0f', $value);
        }

        return rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{0: array<int, array<int, string>>, 1: array<int, array<int, list<string>>>, 2: array{sheets: list<string>, sheet: string|null, encoding: string|null, delimiter: string|null}}
     */
    private function readCsv(string $path, array $options): array
    {
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw ImportFileRejected::unreadable();
        }

        [$encoding, $body] = $this->decode($bytes, isset($options['encoding']) ? (string) $options['encoding'] : null);
        $delimiter = isset($options['delimiter']) && in_array($options['delimiter'], self::DELIMITERS, true)
            ? (string) $options['delimiter']
            : $this->inferDelimiter($body);

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw ImportFileRejected::unreadable();
        }
        fwrite($handle, $body);
        rewind($handle);

        $grid = [];
        $number = 0;
        $maxRecords = (int) config('assets.import.max_rows') * 2 + 50;
        while (($record = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            $number++;
            if ($number > $maxRecords) {
                fclose($handle);
                throw new ImportFileRejected('file_too_long', sprintf('This file has more than %s rows; at most %s can be imported at once. Split it into smaller files.', number_format($maxRecords), number_format((int) config('assets.import.max_rows'))));
            }
            $cells = [];
            foreach ($record as $i => $value) {
                $value = trim((string) $value);
                if ($value !== '') {
                    $cells[(int) $i] = $value;
                }
            }
            $grid[$number] = $cells;
        }
        fclose($handle);

        return [$grid, [], ['sheets' => [], 'sheet' => null, 'encoding' => $encoding, 'delimiter' => $delimiter]];
    }

    /**
     * @return array{0: string, 1: string} encoding, UTF-8 text
     */
    private function decode(string $bytes, ?string $requested): array
    {
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            [$encoding, $bytes] = ['UTF-8', substr($bytes, 3)];
        } elseif (str_starts_with($bytes, "\xFF\xFE")) {
            [$encoding, $bytes] = ['UTF-16LE', substr($bytes, 2)];
        } elseif (str_starts_with($bytes, "\xFE\xFF")) {
            [$encoding, $bytes] = ['UTF-16BE', substr($bytes, 2)];
        } else {
            $encoding = mb_check_encoding($bytes, 'UTF-8') ? 'UTF-8' : 'Windows-1252';
        }

        if ($requested !== null) {
            if (! in_array($requested, self::ENCODINGS, true)) {
                throw new ImportFileRejected('file_encoding', 'That text encoding is not supported.');
            }
            $encoding = $requested;
        }

        $text = $encoding === 'UTF-8' ? $bytes : mb_convert_encoding($bytes, 'UTF-8', $encoding);
        if (! mb_check_encoding($text, 'UTF-8')) {
            throw new ImportFileRejected('file_encoding', 'This file is not valid text in that encoding. Choose another encoding, or save it again as CSV UTF-8.');
        }

        return [$encoding, $text];
    }

    /** The delimiter that splits the first lines most consistently. */
    private function inferDelimiter(string $body): string
    {
        $lines = array_slice(array_values(array_filter(preg_split('/\r\n|\n|\r/', $body) ?: [], fn (string $l): bool => trim($l) !== '')), 0, 10);
        $best = ',';
        $bestScore = 0;
        foreach (self::DELIMITERS as $delimiter) {
            $counts = array_map(fn (string $l): int => substr_count($l, $delimiter), $lines);
            if ($counts === [] || min($counts) === 0) {
                continue;
            }
            $score = min($counts) * 10 - (max($counts) - min($counts));
            if ($score > $bestScore) {
                [$best, $bestScore] = [$delimiter, $score];
            }
        }

        return $best;
    }
}
