<?php

declare(strict_types=1);

namespace App\Domains\Assets\Exceptions;

use RuntimeException;

/**
 * An uploaded spreadsheet the import refuses to read (Phase 2.10 R6). `code`
 * is stable (`file_unreadable`, `file_macros`, `file_too_long`, …) and the
 * message is plain language for the administrator; neither carries a path or
 * library detail.
 */
final class ImportFileRejected extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function unreadable(): self
    {
        return new self('file_unreadable', 'This file could not be read. Save it again from Excel as .xlsx or as CSV and upload that copy.');
    }
}
