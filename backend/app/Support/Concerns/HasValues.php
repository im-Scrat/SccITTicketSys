<?php

declare(strict_types=1);

namespace App\Support\Concerns;

/**
 * Helper methods for string-backed enums used as database CHECK domains
 * and Eloquent casts.
 */
trait HasValues
{
    /**
     * All backing values, e.g. ['active', 'inactive', ...].
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * All case names, e.g. ['Active', 'Inactive', ...].
     *
     * @return array<int, string>
     */
    public static function names(): array
    {
        return array_column(self::cases(), 'name');
    }

    /**
     * Value => human label options, suitable for API/frontend selects.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $case): array => [
                'value' => $case->value,
                'label' => $case->label(),
            ],
            self::cases(),
        );
    }

    /**
     * Default human label derived from the case name (overridable per enum).
     */
    public function label(): string
    {
        return ucwords(str_replace('_', ' ', $this->value));
    }
}
