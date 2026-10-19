<?php

declare(strict_types=1);

namespace App\Domains\Assets\Http\Requests\Concerns;

use Illuminate\Validation\Validator;

/**
 * Validates the comma-separated multi-value filters the asset directories
 * accept (`?status=in_repair,out_of_service`).
 *
 * These cannot use a plain `Rule::in`, because the parameter is a *list* rather
 * than one value. The alternative — letting the directory query silently drop
 * anything it does not recognize — would show the operator an unfiltered table
 * while their filter chip still claimed to be filtering. Reporting the bad value
 * is the honest behaviour.
 */
trait ValidatesFilterLists
{
    /**
     * @param  list<string>  $allowed
     */
    protected function validateFilterList(Validator $validator, string $field, array $allowed): void
    {
        $raw = $this->input($field);

        if (! is_string($raw) || $raw === '' || $raw === 'all') {
            return;
        }

        foreach (explode(',', $raw) as $value) {
            $value = trim($value);

            if ($value !== '' && ! in_array($value, $allowed, true)) {
                $validator->errors()->add($field, "\"{$value}\" is not a valid {$field} value.");
            }
        }
    }
}
