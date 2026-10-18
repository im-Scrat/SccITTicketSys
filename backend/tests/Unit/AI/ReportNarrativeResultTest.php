<?php

declare(strict_types=1);

use App\Domains\Analytics\DTOs\ReportEvidencePacket;
use App\Domains\Analytics\DTOs\ReportFact;
use App\Domains\KnowledgeBase\DTOs\ReportNarrativeResult;

/*
 * Phase 2.10 R5 — every AI statement is checked against the facts it cites
 * (SDD DD-95). One accepted baseline, then each rule that withholds it.
 */

beforeEach(function (): void {
    $this->packet = new ReportEvidencePacket(
        questions: [
            'q1' => ['sufficient' => true, 'reason' => null, 'facts' => [
                new ReportFact('q1.pc_1.corrective_replacement_events', 'q1', 'Corrective part replacements on PC-001', 3, 'replacements', 'basis', 3),
                new ReportFact('q1.pcs_with_two_or_more', 'q1', 'PC units with two or more', 1, 'PC units', 'basis', 3),
            ]],
            'q2' => ['sufficient' => true, 'reason' => null, 'facts' => [
                new ReportFact('q2.room_1.pc_problems', 'q2', 'PC problems in LAB-A', 8, 'problems', 'basis', 8),
                new ReportFact('q2.share', 'q2', 'Share', 2.5, 'ratio', 'basis', 8),
            ]],
            'q3' => ['sufficient' => false, 'reason' => 'Too few.', 'facts' => [
                new ReportFact('q3.known_type_events', 'q3', 'Known', 1, 'replacements', 'basis', 1),
            ]],
            'q4' => ['sufficient' => false, 'reason' => 'Too few.', 'facts' => []],
            'q5' => ['sufficient' => false, 'reason' => 'Too few.', 'facts' => []],
        ],
        scope: 'Period: 2026-10-01 to 2026-10-18',
        limitations: [],
    );

    $this->coverage = ['q1' => 'answered', 'q2' => 'answered', 'q3' => 'insufficient_data', 'q4' => 'insufficient_data', 'q5' => 'insufficient_data'];
    $this->finding = fn (array $overrides = []): array => [
        'question' => 'q1',
        'kind' => 'observation',
        'text' => 'PC-001 had 3 corrective part replacements in the period.',
        'cited_fact_keys' => ['q1.pc_1.corrective_replacement_events'],
        'confidence' => 'low',
        ...$overrides,
    ];
    $this->check = fn (array $finding): ReportNarrativeResult => ReportNarrativeResult::fromStructured(
        ['coverage' => $this->coverage, 'findings' => [$finding], 'limitations' => []],
        $this->packet,
    );
});

it('accepts a statement that cites its facts and uses their numbers', function (): void {
    $result = ($this->check)(($this->finding)());

    expect($result->findings)->toHaveCount(1)
        ->and($result->withheld)->toBe(0);
});

dataset('withheld statements', [
    'no citation' => [['cited_fact_keys' => []], 'no_citation'],
    'unknown key' => [['cited_fact_keys' => ['q1.pc_9.anything']], 'unknown_citation'],
    'key of another question' => [['cited_fact_keys' => ['q2.room_1.pc_problems']], 'unknown_citation'],
    'insufficient question' => [['question' => 'q3', 'cited_fact_keys' => ['q3.known_type_events'], 'text' => 'There was 1 known replacement.'], 'insufficient_question'],
    'invented number' => [['text' => 'PC-001 had 7 corrective part replacements.'], 'number_not_in_facts'],
    'derived quantity' => [['text' => 'PC-001 needed twice as many replacements as usual.'], 'derived_quantity'],
    'certainty' => [['kind' => 'recommendation', 'text' => 'PC-001 will certainly fail again; replace it.'], 'certainty_or_probability'],
    'probability' => [['text' => 'There is an 80% chance PC-001 fails.'], 'certainty_or_probability'],
    'link' => [['text' => 'See www.example.com for PC-001 support.'], 'link_or_markup'],
    'markup' => [['text' => 'PC-001 had <b>3</b> replacements.'], 'link_or_markup'],
    'contact instruction' => [['kind' => 'recommendation', 'text' => 'Contact the vendor about PC-001.'], 'link_or_markup'],
    'unhedged explanation' => [['kind' => 'possible_explanation', 'text' => 'The power supply in PC-001 is faulty.'], 'unhedged_explanation'],
    'causal observation' => [['text' => 'PC-001 had 3 replacements because of heat.'], 'causal_observation'],
    'confident on thin evidence' => [['confidence' => 'high'], 'unsupported_confidence'],
    'too long' => [['text' => str_repeat('PC-001 had 3 corrective replacements. ', 12)], 'length'],
]);

it('withholds a statement that breaks an evidence rule', function (array $overrides, string $reason): void {
    $result = ($this->check)(($this->finding)($overrides));

    expect($result->findings)->toBe([])
        ->and($result->withheld)->toBe(1)
        ->and($result->withheldReasons)->toBe([$reason => 1]);
})->with('withheld statements');

it('accepts whole-number rounding of a cited decimal and a ratio behind a derived word', function (): void {
    $result = ($this->check)(($this->finding)([
        'question' => 'q2',
        'text' => 'LAB-A had 8 PC problems, about 3 per PC, which is double the norm in the cited ratio.',
        'cited_fact_keys' => ['q2.room_1.pc_problems', 'q2.share'],
        'confidence' => 'medium',
    ]));

    expect($result->withheld)->toBe(0);
});

it('keeps at most four statements per question', function (): void {
    $finding = ($this->finding)();
    $result = ReportNarrativeResult::fromStructured(['coverage' => $this->coverage, 'findings' => array_fill(0, 6, $finding), 'limitations' => []], $this->packet);

    expect($result->findings)->toHaveCount(4)
        ->and($result->withheldReasons)->toBe(['too_many' => 2]);
});

it('overrides a coverage claim for a question the facts do not support', function (): void {
    $result = ReportNarrativeResult::fromStructured(['coverage' => [...$this->coverage, 'q3' => 'answered'], 'findings' => [], 'limitations' => []], $this->packet);

    expect($result->coverage['q3'])->toBe('insufficient_data');
});

it('fails a structurally malformed answer as a whole', function (): void {
    ReportNarrativeResult::fromStructured(['findings' => []], $this->packet);
})->throws(InvalidArgumentException::class);

it('drops limitation lines with numbers, links or certainty', function (): void {
    $result = ReportNarrativeResult::fromStructured(['coverage' => $this->coverage, 'findings' => [], 'limitations' => [
        'Small samples can mislead.',
        'Only 3 records exist.',
        'See https://example.com.',
    ]], $this->packet);

    expect($result->limitations)->toBe(['Small samples can mislead.']);
});
