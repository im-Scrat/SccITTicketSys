<?php

declare(strict_types=1);

namespace App\Support\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Validator;

/**
 * Reject an unknown slug in a comma-separated filter rather than ignoring it:
 * showing an unfiltered list under a filter chip is worse than an error (the
 * rule established in Phase 2.5).
 *
 * Moved unchanged from IndexTicketsRequest in Phase 2.10 so the ticket
 * directory and the report requests validate the same lists the same way — a
 * drill-down link from a report carries exactly the parameters the directory
 * then checks.
 *
 * For FormRequests: call it from `after()`.
 */
trait ValidatesLookupSlugs
{
    protected function validateLookupSlugs(Validator $validator, string $field, string $table): void
    {
        $raw = $this->input($field);

        if (! is_string($raw) || $raw === '' || $raw === 'all') {
            return;
        }

        $submitted = array_values(array_filter(array_map('trim', explode(',', $raw))));

        if ($submitted === []) {
            return;
        }

        $known = DB::table($table)->whereIn('slug', $submitted)->pluck('slug')->all();

        foreach (array_diff($submitted, $known) as $unknown) {
            $validator->errors()->add($field, "\"{$unknown}\" is not a valid {$field}.");
        }
    }
}
