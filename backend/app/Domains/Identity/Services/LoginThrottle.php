<?php

declare(strict_types=1);

namespace App\Domains\Identity\Services;

use Illuminate\Support\Str;

/**
 * The single source of truth for the login RateLimiter key format
 * (SRS FR-AUTH-006; SDD DD-20). Shared by AuthService (which increments the
 * limiter on failed attempts) and AccountLockService (which reads/clears it for
 * the administrator "unlock account" action, FR-USER). Keeping one canonical
 * key here prevents the two sides from drifting.
 */
final class LoginThrottle
{
    /**
     * Build the per-(email, ip) throttle key. `$ip` is passed through verbatim so
     * the format is byte-identical to the historical inline key in AuthService.
     */
    public static function key(string $email, ?string $ip): string
    {
        return 'login:'.Str::transliterate(Str::lower($email)).'|'.$ip;
    }
}
