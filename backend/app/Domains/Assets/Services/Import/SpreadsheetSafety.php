<?php

declare(strict_types=1);

namespace App\Domains\Assets\Services\Import;

use App\Domains\Assets\Exceptions\ImportFileRejected;
use ZipArchive;

/**
 * Opens an uploaded .xlsx as the zip container it is and refuses anything a
 * spreadsheet of an inventory has no reason to contain, before a single cell
 * is parsed (Phase 2.10 R6, SDD DD-96).
 *
 * The MIME check in `AttachmentSecurity` proves only that the bytes are a zip.
 * What is *inside* is decided here:
 *
 *  - **Macros** (`vbaProject`, a macro-enabled content type) and **active
 *    content** (`xl/activeX`, `xl/ctrlProps`), **embedded objects**
 *    (`xl/embeddings`) and **external links** (`xl/externalLinks`, which can
 *    make a reader fetch a remote workbook) are refused outright.
 *  - **Zip bombs**: too many entries, too much expanded data, or a compression
 *    ratio no real workbook reaches.
 *  - **Path tricks**: an entry named with `..` or an absolute path.
 *  - **Encryption**: an encrypted workbook is not a zip at all (it is an OLE
 *    container), so it never gets this far; a zip that carries an
 *    `EncryptionInfo` part is refused for the same reason.
 *
 * The worksheet and shared-strings parts are size-checked separately by the
 * reader before it loads them (PhpSpreadsheet holds a whole sheet in memory).
 * XML entity tricks are refused by PhpSpreadsheet's own XML scanner, which
 * surfaces as `file_unreadable`.
 */
final class SpreadsheetSafety
{
    private const FORBIDDEN_PREFIXES = ['xl/externallinks/', 'xl/embeddings/', 'xl/activex/', 'xl/ctrlprops/'];

    public function check(string $path, string $extension): void
    {
        if (strtolower($extension) !== 'xlsx') {
            return;
        }

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw ImportFileRejected::unreadable();
        }

        try {
            $this->inspect($zip);
        } finally {
            $zip->close();
        }
    }

    private function inspect(ZipArchive $zip): void
    {
        $limits = config('assets.import');

        if ($zip->numFiles > (int) $limits['max_zip_entries']) {
            throw new ImportFileRejected('file_too_complex', 'This file contains far more parts than an inventory spreadsheet would. Save it again as a plain .xlsx or CSV.');
        }

        $expanded = 0;
        $compressed = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                throw ImportFileRejected::unreadable();
            }

            $name = (string) $stat['name'];
            $lower = strtolower($name);

            if (str_contains($name, '..') || str_starts_with($name, '/') || str_starts_with($name, '\\') || preg_match('/^[a-z]:/i', $name)) {
                throw ImportFileRejected::unreadable();
            }
            if (str_contains($lower, 'vbaproject') || $lower === 'encryptioninfo' || $lower === 'encryptedpackage') {
                throw new ImportFileRejected('file_macros', 'Files with macros or encryption are not accepted. Save a copy as a plain Excel workbook (.xlsx) and upload that.');
            }
            foreach (self::FORBIDDEN_PREFIXES as $prefix) {
                if (str_starts_with($lower, $prefix)) {
                    throw new ImportFileRejected('file_active_content', 'This file contains embedded objects, controls or links to other workbooks. Copy the data into a new workbook and upload that.');
                }
            }

            $expanded += (int) $stat['size'];
            $compressed += (int) $stat['comp_size'];
        }

        if ($expanded > (int) $limits['max_uncompressed_mb'] * 1024 * 1024
            || ($compressed > 0 && $expanded / $compressed > (int) $limits['max_compression_ratio'])) {
            throw new ImportFileRejected('file_too_complex', 'This file expands to far more data than an inventory spreadsheet would. Save it again as a plain .xlsx or CSV.');
        }

        $types = $zip->getFromName('[Content_Types].xml');
        if ($types === false) {
            throw ImportFileRejected::unreadable();
        }
        if (stripos($types, 'macroEnabled') !== false) {
            throw new ImportFileRejected('file_macros', 'Files with macros or encryption are not accepted. Save a copy as a plain Excel workbook (.xlsx) and upload that.');
        }
    }

    /**
     * The expanded size of one part, or null when the part is absent — for the
     * reader's per-sheet check.
     */
    public static function partSize(string $path, string $part): ?int
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return null;
        }

        try {
            $stat = $zip->statName($part);

            return $stat === false ? null : (int) $stat['size'];
        } finally {
            $zip->close();
        }
    }
}
