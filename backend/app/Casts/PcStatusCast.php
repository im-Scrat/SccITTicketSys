<?php

declare(strict_types=1);

namespace App\Casts;

use App\Enums\PcStatus;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * `PcStatus` cast that reads a legacy stored value as its mapped successor
 * (see {@see PcStatus::fromStored()}) instead of throwing.
 *
 * Writes stay strict: only the three current values can be stored, and the
 * database CHECK says the same.
 *
 * @implements CastsAttributes<PcStatus|null, PcStatus|string|null>
 */
final class PcStatusCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?PcStatus
    {
        return $value === null ? null : PcStatus::fromStored((string) $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof PcStatus) {
            return $value->value;
        }

        if (PcStatus::tryFrom($value) !== null) {
            return $value;
        }

        throw new InvalidArgumentException("Unsupported PC status [{$value}].");
    }
}
