<?php

declare(strict_types=1);

use App\Domains\KnowledgeBase\Services\KnowledgeChunker;
use App\Models\AiKnowledgeArticle;

/**
 * WP-P — how an article is cut before it is embedded.
 *
 * Pure text in, chunks out, so these need no database: the article is an
 * unsaved model. What is worth proving is the contract everything downstream
 * relies on — determinism, that no chunk overruns the limit, that no text is
 * lost, and that the hash moves exactly when the *content* does.
 */
function article(array $attributes = []): AiKnowledgeArticle
{
    return new AiKnowledgeArticle([
        'title' => 'Projector will not power on',
        'category' => 'Hardware',
        'problem_signature' => 'The projector shows no light and no fan noise when switched on.',
        'root_cause' => 'The power lead is not fully seated, or the outlet has no power.',
        'verified_solution' => 'Reseat the power lead at both ends, test the outlet with another device, then try again.',
        ...$attributes,
    ]);
}

/** A long section made of numbered sentences, so loss and order are checkable. */
function longText(int $sentences): string
{
    return implode(' ', array_map(
        static fn (int $n): string => "Step {$n} is to check the connection and confirm the indicator light is steady.",
        range(1, $sentences),
    ));
}

$chunker = new KnowledgeChunker;

it('gives a short article one chunk per non-empty section, each opening with title and section', function () use ($chunker): void {
    $chunks = $chunker->chunk(article());

    expect($chunks)->toHaveCount(4)
        ->and(collect($chunks)->pluck('section')->all())->toBe(['Category', 'Problem', 'Cause', 'Solution'])
        ->and($chunks[1]->text)->toStartWith("Projector will not power on — Problem\n")
        ->and($chunks[1]->text)->toContain('no fan noise')
        ->and(collect($chunks)->pluck('index')->all())->toBe([0, 1, 2, 3]);
});

it('skips sections with nothing in them rather than embedding a header on its own', function () use ($chunker): void {
    $chunks = $chunker->chunk(article(['category' => null, 'root_cause' => '   ', 'problem_signature' => null]));

    expect(collect($chunks)->pluck('section')->all())->toBe(['Solution']);
});

it('still yields one chunk for an article that is only a title', function () use ($chunker): void {
    $chunks = $chunker->chunk(article(['category' => null, 'problem_signature' => null, 'root_cause' => null, 'verified_solution' => null]));

    expect($chunks)->toHaveCount(1)
        ->and($chunks[0]->text)->toBe('Projector will not power on');
});

it('is deterministic — the same article always yields the same chunks', function () use ($chunker): void {
    $article = article(['verified_solution' => longText(60)]);

    expect($chunker->chunk($article))->toEqual($chunker->chunk($article))
        ->and($chunker->hash($article))->toBe($chunker->hash($article));
});

it('keeps every chunk within the limit, however long the section', function () use ($chunker): void {
    $chunks = $chunker->chunk(article(['verified_solution' => longText(200)]));

    $solution = array_values(array_filter($chunks, fn ($c): bool => $c->section === 'Solution'));
    $header = mb_strlen("Projector will not power on — Solution\n");

    expect(count($solution))->toBeGreaterThan(5);

    foreach ($solution as $chunk) {
        expect(mb_strlen($chunk->text) - $header)->toBeLessThanOrEqual(KnowledgeChunker::MAX_CHARS);
    }
});

it('loses no text when it splits — every sentence appears in at least one chunk', function () use ($chunker): void {
    $chunks = $chunker->chunk(article(['verified_solution' => longText(80)]));
    $all = implode("\n", array_map(fn ($c): string => $c->text, $chunks));

    foreach (range(1, 80) as $n) {
        expect($all)->toContain("Step {$n} is to check the connection");
    }
});

it('carries an overlap forward so a sentence at a boundary is not orphaned', function () use ($chunker): void {
    $chunks = array_values(array_filter(
        $chunker->chunk(article(['verified_solution' => longText(80)])),
        fn ($c): bool => $c->section === 'Solution',
    ));

    // The tail of one chunk reappears at the head of the next.
    for ($i = 1; $i < count($chunks); $i++) {
        $previousWords = explode(' ', trim($chunks[$i - 1]->text));
        $lastWords = implode(' ', array_slice($previousWords, -6));

        expect($chunks[$i]->text)->toContain($lastWords);
    }
});

it('splits a paragraph-structured section on paragraph boundaries where it can', function () use ($chunker): void {
    $paragraphs = array_map(fn (int $n): string => "Paragraph {$n}: ".str_repeat('word ', 90), range(1, 6));

    $chunks = array_values(array_filter(
        $chunker->chunk(article(['verified_solution' => implode("\n\n", $paragraphs)])),
        fn ($c): bool => $c->section === 'Solution',
    ));

    expect(count($chunks))->toBeGreaterThan(1);

    // No chunk begins or ends mid-paragraph-label: each carries whole labels.
    foreach ($chunks as $chunk) {
        expect(preg_match_all('/Paragraph \d:/', $chunk->text))->toBeGreaterThanOrEqual(1);
    }
});

it('cuts a single unbroken run longer than the limit by characters rather than dropping it', function () use ($chunker): void {
    $run = str_repeat('a', KnowledgeChunker::MAX_CHARS * 2 + 50);

    $chunks = array_values(array_filter(
        $chunker->chunk(article(['verified_solution' => $run])),
        fn ($c): bool => $c->section === 'Solution',
    ));

    expect(implode('', array_map(fn ($c): string => substr($c->text, strpos($c->text, "\n") + 1), $chunks)))
        ->toContain(str_repeat('a', KnowledgeChunker::MAX_CHARS));

    expect(count($chunks))->toBeGreaterThanOrEqual(3);
});

it('never breaks a multibyte character', function () use ($chunker): void {
    $text = implode(' ', array_fill(0, 400, 'Проверьте кабель питания и убедитесь, что индикатор горит ровно.'));

    foreach ($chunker->chunk(article(['verified_solution' => $text])) as $chunk) {
        expect(mb_check_encoding($chunk->text, 'UTF-8'))->toBeTrue();
    }
});

it('hashes the content, so an edit to any embedded field changes it', function () use ($chunker): void {
    $base = $chunker->hash(article());

    expect($chunker->hash(article(['verified_solution' => 'Replace the power lead.'])))->not->toBe($base)
        ->and($chunker->hash(article(['title' => 'Projector is dead'])))->not->toBe($base)
        ->and($chunker->hash(article(['category' => 'Network'])))->not->toBe($base)
        ->and($chunker->hash(article(['root_cause' => 'A blown fuse.'])))->not->toBe($base)
        ->and($chunker->hash(article(['problem_signature' => 'It smells of burning.'])))->not->toBe($base);
});

it('does not treat formatting as a content change, so it costs no provider call', function () use ($chunker): void {
    $base = $chunker->hash(article());

    // Padding, repeated blanks, CRLF line endings and trailing blank lines are
    // not content: re-indenting or re-wrapping an article must not re-index it.
    expect($chunker->hash(article(['problem_signature' => "  The projector shows no light   and no fan noise when switched on.  \r\n\r\n\r\n"])))
        ->toBe($base)
        ->and($chunker->hash(article(['title' => '  Projector will not power on '])))->toBe($base);
});

it('ignores fields that are not part of what is embedded', function () use ($chunker): void {
    $base = $chunker->hash(article());

    // Counters, publication metadata and provenance are not content.
    expect($chunker->hash(article(['verification_count' => 99, 'slug' => 'other', 'created_from_ticket_id' => 5])))->toBe($base);
});

it('lets the chunker\'s own version invalidate every hash', function () use ($chunker): void {
    // A future change to how articles are cut bumps VERSION, and that must make
    // every existing hash stale — otherwise old-shape vectors would sit beside
    // new-shape ones for ever, each looking current.
    $next = new class extends KnowledgeChunker
    {
        public const VERSION = KnowledgeChunker::VERSION + 1;
    };

    expect($next->hash(article()))->not->toBe($chunker->hash(article()));
});

it('estimates tokens without ever reporting zero for real text', function () use ($chunker): void {
    foreach ($chunker->chunk(article()) as $chunk) {
        expect($chunk->tokenEstimate)->toBeGreaterThan(0)
            ->and($chunk->hash)->toBe(hash('sha256', $chunk->text));
    }
});
