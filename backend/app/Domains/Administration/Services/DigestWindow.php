<?php

declare(strict_types=1);

namespace App\Domains\Administration\Services;

use Carbon\CarbonImmutable;

/**
 * The school-local calendar day a digest run covers (SRS FR-NOT-008).
 *
 * A value object rather than three loose arguments, because the three parts
 * must never be computed separately: `date` is what the idempotency record is
 * keyed on, and `start`/`end` are the instants that decide which rows belong to
 * it. Passing them around as one thing is what stops a query and a claim ever
 * disagreeing about which day they are talking about.
 *
 * The interval is **half-open** — `[start, end)`. A notification created at
 * exactly the closing instant belongs to the next day, not this one.
 *
 * `date` is the day **summarised**, not the day the mail is sent: the 07:00
 * Asia/Manila run on the 8th carries `date = 2026-09-07`.
 */
readonly class DigestWindow
{
    public function __construct(
        /** School-local calendar day, `Y-m-d` — the digest's identity. */
        public string $date,
        /** First instant of that day, inclusive. */
        public CarbonImmutable $start,
        /** First instant of the following day, exclusive. */
        public CarbonImmutable $end,
    ) {}

    /** How the day reads to a person, e.g. "Sunday, 7 September 2026". */
    public function label(): string
    {
        return $this->start->translatedFormat('l, j F Y');
    }
}
