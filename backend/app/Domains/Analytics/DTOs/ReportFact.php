<?php

declare(strict_types=1);

namespace App\Domains\Analytics\DTOs;

/**
 * One fact the application computed for an AI interpretation (Phase 2.10 R5,
 * SDD DD-95). Stable `key` (`q1.pc_1.corrective_replacement_events`), the
 * value, its unit, how it was counted, and how many records it rests on — the
 * validator uses the last to refuse confident claims on thin evidence.
 */
final readonly class ReportFact
{
    public function __construct(
        public string $key,
        public string $question,
        public string $label,
        public int|float|string $value,
        public ?string $unit,
        public string $basis,
        public int $recordCount,
    ) {}

    /**
     * @return array{key: string, question: string, label: string, value: int|float|string, unit: string|null, basis: string, record_count: int}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'question' => $this->question,
            'label' => $this->label,
            'value' => $this->value,
            'unit' => $this->unit,
            'basis' => $this->basis,
            'record_count' => $this->recordCount,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            (string) $row['key'],
            (string) $row['question'],
            (string) $row['label'],
            is_int($row['value']) || is_float($row['value']) ? $row['value'] : (string) $row['value'],
            isset($row['unit']) ? (string) $row['unit'] : null,
            (string) $row['basis'],
            (int) $row['record_count'],
        );
    }
}
