<?php

declare(strict_types=1);

namespace App\Domains\Assets\Exports;

use App\Domains\Assets\Services\Import\ImportFieldDictionary;
use App\Enums\AssetImportEntityMode;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The blank import template for one kind of file (Phase 2.10 R6).
 *
 * Its headers are the field dictionary's template headers, so a file built
 * from it maps every column exactly ("All columns matched the template") and
 * a clean one goes straight to Confirm. No example row: an example left in
 * place would be imported as a real PC.
 */
final class AssetImportTemplateExport implements FromArray, ShouldAutoSize, WithHeadings, WithStyles
{
    public function __construct(private readonly AssetImportEntityMode $mode) {}

    /**
     * @return list<list<string>>
     */
    public function array(): array
    {
        return [];
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return array_map(
            fn (string $target): string => ImportFieldDictionary::templateHeader($target),
            ImportFieldDictionary::templateTargets($this->mode),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function styles(Worksheet $sheet): array
    {
        $sheet->freezePane('A2');

        return [1 => ['font' => ['bold' => true]]];
    }
}
