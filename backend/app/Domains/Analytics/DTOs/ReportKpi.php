<?php

declare(strict_types=1);

namespace App\Domains\Analytics\DTOs;

/**
 * A headline figure in a report document, already in words (Phase 2.10).
 */
final readonly class ReportKpi
{
    public function __construct(
        public string $label,
        public string $value,
        public ?string $detail = null,
    ) {}

    /**
     * @return array{label: string, value: string, detail: string|null}
     */
    public function toArray(): array
    {
        return ['label' => $this->label, 'value' => $this->value, 'detail' => $this->detail];
    }
}
