<?php

declare(strict_types=1);

namespace App\Domains\Identity\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Configurable password policy (SRS FR-AUTH-003): minimum length, at least N of
 * {lowercase, uppercase, digit, symbol}, and rejection of the most common
 * passwords. The common-password check is fully offline (bundled list) to
 * respect locked-down/no-CDN networks (NFR-CMP-005) — no HaveIBeenPwned call.
 */
class PasswordPolicy implements ValidationRule
{
    /** @var array<string, int>|null */
    private static ?array $common = null;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute must be a string.');

            return;
        }

        $min = (int) config('security.password.min_length', 10);
        $minClasses = (int) config('security.password.min_classes', 3);

        if (mb_strlen($value) < $min) {
            $fail("The :attribute must be at least {$min} characters long.");

            return;
        }

        $classes = 0;
        $classes += preg_match('/\p{Ll}/u', $value) ? 1 : 0;
        $classes += preg_match('/\p{Lu}/u', $value) ? 1 : 0;
        $classes += preg_match('/\d/', $value) ? 1 : 0;
        $classes += preg_match('/[^\p{L}\d]/u', $value) ? 1 : 0;

        if ($classes < $minClasses) {
            $fail("The :attribute must contain at least {$minClasses} of the following: a lowercase letter, an uppercase letter, a digit, and a symbol.");

            return;
        }

        if ($this->isCommon($value)) {
            $fail('The :attribute is too common. Please choose a less predictable password.');
        }
    }

    private function isCommon(string $value): bool
    {
        if (self::$common === null) {
            /** @var list<string> $list */
            $list = require __DIR__.'/../resources/common-passwords.php';
            self::$common = array_flip(array_map('strtolower', $list));
        }

        return isset(self::$common[mb_strtolower($value)]);
    }
}
