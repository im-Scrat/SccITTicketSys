<?php

declare(strict_types=1);

namespace App\Support\Exports;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

/**
 * Binds text that looks like a formula as text (Phase 2.10, SDD DD-85).
 *
 * A room named `=HYPERLINK("http://…","Lab 2")` — or a ticket title starting
 * with `+`, `-` or `@` — would otherwise be written as a live formula and run
 * when the spreadsheet is opened (CSV/formula injection). Strings that start
 * with `=`, `+`, `-`, `@`, TAB or CR are therefore stored with the string type:
 * Excel shows exactly what was typed and evaluates nothing.
 *
 * Installed for **every** spreadsheet the application writes — report
 * workbooks set it directly and maatwebsite/excel exports (the user directory)
 * get it from `config/excel.php` — so no export can be written without it.
 * Numbers are untouched: a negative number is still a number, because only a
 * *string* reaches the check.
 */
final class SafeValueBinder extends DefaultValueBinder
{
    private const TRIGGERS = ['=', '+', '-', '@', "\t", "\r"];

    public function bindValue(Cell $cell, mixed $value): bool
    {
        if (is_string($value) && $value !== '' && in_array($value[0], self::TRIGGERS, true)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }

    /** True when a plain-text cell (CSV) must be neutralised with a leading apostrophe. */
    public static function needsEscaping(string $value): bool
    {
        return $value !== '' && in_array($value[0], self::TRIGGERS, true);
    }
}
