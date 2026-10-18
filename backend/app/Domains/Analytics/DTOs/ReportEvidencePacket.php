<?php

declare(strict_types=1);

namespace App\Domains\Analytics\DTOs;

use App\Enums\ReportQuestion;

/**
 * Everything an AI interpretation of the overall report may be told (Phase
 * 2.10 R5, SDD DD-95): per question, whether it has enough recorded data, why
 * not when it does not, and its facts; the scope in codes and enums; the
 * limitations stated by the application.
 *
 * **What it never contains:** names, ticket titles or descriptions, notes,
 * costs, purchase prices or uuids — only counts, component types, category
 * slugs, equipment and room codes. `factsHash` (sha256 over its canonical JSON)
 * binds an interpretation to exactly these facts.
 */
final readonly class ReportEvidencePacket
{
    /**
     * @param  array<string, array{sufficient: bool, reason: string|null, facts: list<ReportFact>}>  $questions
     * @param  list<string>  $limitations
     */
    public function __construct(
        public array $questions,
        public string $scope,
        public array $limitations,
    ) {}

    public function anySufficient(): bool
    {
        foreach ($this->questions as $q) {
            if ($q['sufficient']) {
                return true;
            }
        }

        return false;
    }

    public function isSufficient(string $question): bool
    {
        return (bool) ($this->questions[$question]['sufficient'] ?? false);
    }

    /** The fact with this key, or null. */
    public function fact(string $key): ?ReportFact
    {
        foreach ($this->questions as $q) {
            foreach ($q['facts'] as $fact) {
                if ($fact->key === $key) {
                    return $fact;
                }
            }
        }

        return null;
    }

    public function factsHash(): string
    {
        return hash('sha256', json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Keys whose value differs between this packet and an older one (for
     * "data changed since generated").
     *
     * @return list<string>
     */
    public function changedFrom(self $older): array
    {
        $changed = [];
        foreach ($this->questions as $q) {
            foreach ($q['facts'] as $fact) {
                $previous = $older->fact($fact->key);
                if ($previous === null || $previous->value !== $fact->value) {
                    $changed[] = $fact->key;
                }
            }
        }
        foreach ($older->questions as $q) {
            foreach ($q['facts'] as $fact) {
                if ($this->fact($fact->key) === null) {
                    $changed[] = $fact->key;
                }
            }
        }

        return array_values(array_unique($changed));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $questions = [];
        foreach (ReportQuestion::cases() as $case) {
            $q = $this->questions[$case->value] ?? ['sufficient' => false, 'reason' => 'Not computed.', 'facts' => []];
            $questions[$case->value] = [
                'question' => $case->label(),
                'sufficient' => $q['sufficient'],
                'reason' => $q['reason'],
                'facts' => array_map(static fn (ReportFact $f): array => $f->toArray(), $q['facts']),
            ];
        }

        return ['scope' => $this->scope, 'questions' => $questions, 'limitations' => $this->limitations];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $questions = [];
        foreach ((array) ($data['questions'] ?? []) as $key => $q) {
            $questions[(string) $key] = [
                'sufficient' => (bool) $q['sufficient'],
                'reason' => isset($q['reason']) ? (string) $q['reason'] : null,
                'facts' => array_map(static fn (array $f): ReportFact => ReportFact::fromArray($f), (array) $q['facts']),
            ];
        }

        return new self($questions, (string) ($data['scope'] ?? ''), array_values(array_map('strval', (array) ($data['limitations'] ?? []))));
    }
}
