<?php

declare(strict_types=1);

namespace App\Domains\Assets\Services\Import;

use App\Enums\AssetImportEntityMode;

/**
 * Finds the header row of a sheet (Phase 2.10 R6).
 *
 * School inventories often start with a title, a date or a blank line or two.
 * Of the first twenty non-empty rows, the one whose cells look most like
 * column headers wins: three points for each cell the field dictionary
 * recognises, one for each other text cell; numbers score nothing. A row needs
 * at least two filled cells; ties go to the earlier row. The administrator can
 * always choose another row.
 */
final class HeaderRowDetector
{
    private const SCAN = 20;

    /**
     * @param  array<int, array<int, string>>  $rows  row number => cells
     */
    public function detect(array $rows, AssetImportEntityMode $mode): ?int
    {
        $best = null;
        $bestScore = -1;
        $first = null;
        $seen = 0;

        foreach ($rows as $number => $cells) {
            $filled = array_filter($cells, fn (string $v): bool => trim($v) !== '');
            if ($filled === []) {
                continue;
            }
            $first ??= $number;
            if (++$seen > self::SCAN) {
                break;
            }
            if (count($filled) < 2) {
                continue;
            }

            $score = 0;
            foreach ($filled as $value) {
                if (is_numeric(str_replace([',', ' '], '', $value))) {
                    continue;
                }
                $match = ImportFieldDictionary::match($value, $mode);
                $score += in_array($match['confidence'], ['exact', 'synonym', 'likely', 'ambiguous'], true) ? 3 : 1;
            }

            if ($score > $bestScore) {
                [$best, $bestScore] = [$number, $score];
            }
        }

        return $best ?? $first;
    }
}
