<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Services;

use App\Enums\ReportContentProfile;

/**
 * What a report document should carry beyond its figures (Phase 2.10).
 *
 * `recordLimit` is the most records the document will even fetch; the paged
 * formats then cut further to their row budget (SDD DD-85).
 */
final readonly class ReportBuildOptions
{
    public function __construct(
        public ReportContentProfile $profile = ReportContentProfile::Full,
        public bool $includeRecords = false,
        public int $recordLimit = 10000,
        /** Overall report only: add the AI interpretation, if one exists for exactly this data. */
        public bool $includeNarrative = false,
    ) {}
}
