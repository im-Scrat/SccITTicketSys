<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

/**
 * Report document formats (Phase 2.10, SDD DD-85/DD-91). Each one has a job
 * the others do not: PDF to read and print, Word to edit, Excel for every
 * record with native charts, CSV for one records table.
 */
enum ReportExportFormat: string
{
    use HasValues;

    case Pdf = 'pdf';
    case Docx = 'docx';
    case Xlsx = 'xlsx';
    case Csv = 'csv';

    public function label(): string
    {
        return match ($this) {
            self::Pdf => 'PDF',
            self::Docx => 'Word',
            self::Xlsx => 'Excel',
            self::Csv => 'CSV',
        };
    }

    public function purpose(): string
    {
        return match ($this) {
            self::Pdf => 'To read and print. Charts with their figures; long record lists go to Excel.',
            self::Docx => 'To edit. The same content as the PDF, in Word.',
            self::Xlsx => 'Every record, with native charts and filters.',
            self::Csv => 'One table of records, for another program.',
        };
    }

    public function extension(): string
    {
        return $this->value;
    }

    /** The type the file is served as — the PROFILE_REPORT allow-list. */
    public function mime(): string
    {
        return match ($this) {
            self::Pdf => 'application/pdf',
            self::Docx => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            self::Xlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            self::Csv => 'text/csv',
        };
    }

    /** Paged formats carry paper size and orientation. */
    public function isPaged(): bool
    {
        return $this === self::Pdf || $this === self::Docx;
    }
}
