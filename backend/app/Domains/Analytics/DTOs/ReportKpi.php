<?php

declare(strict_types=1);

namespace App\Domains\Analytics\DTOs;

/**
 * A headline figure in a report document, already in words (Phase 2.10).
 *
 * `figure` (the raw number) and `drilldown` (the ticket list it counts) are for
 * the screen; documents print `value` and `detail` only.
 */
final readonly class ReportKpi
{
    public function __construct(
        public string $label,
        public string $value,
        public ?string $detail = null,
        /** @var array{path: string, query: array<string, string>}|null */
        public ?array $drilldown = null,
        public int|float|null $figure = null,
    ) {}

    /**
     * @return array{label: string, value: string, detail: string|null, drilldown: array<string, mixed>|null, figure: int|float|null}
     */
    public function toArray(): array
    {
        return ['label' => $this->label, 'value' => $this->value, 'detail' => $this->detail, 'drilldown' => $this->drilldown, 'figure' => $this->figure];
    }
}
