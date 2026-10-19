<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Services;

use App\Domains\KnowledgeBase\DTOs\KnowledgeChunk;
use App\Models\AiKnowledgeArticle;

/**
 * Turns a knowledge article into the text that is embedded (WP-P; SRS FR-AI-006).
 *
 * **Deterministic, and nothing but the article.** The same article always yields
 * the same chunks in the same order, and the only input is the article's own
 * curated fields. Nothing about the ticket it may have been drafted from, and
 * nothing about a person, ever reaches the text that is sent to a provider — the
 * minimum-data rule of FR-AI-030 is enforced by there being no path for it to
 * arrive, not by a redaction pass that could miss something.
 *
 * ── Shape ──────────────────────────────────────────────────────────────────
 *
 * The article is read as labelled sections — Category, Problem, Cause,
 * Solution — and each section is packed into chunks of at most
 * {@see MAX_CHARS} characters of body. Every chunk is prefixed with the title
 * and its section label ({@see KnowledgeChunk}), so it is meaningful alone.
 *
 * A section is split at the largest boundary that keeps it under the limit:
 * paragraphs first, then sentences, then — for a single unbroken run longer
 * than the limit — words, and finally characters. Splits inside a section carry
 * the last {@see OVERLAP_CHARS} of the previous chunk forward (cut at a word),
 * because an answer often straddles a boundary and retrieval that has lost the
 * sentence before it retrieves the wrong half.
 *
 * ── The hash is the staleness contract ─────────────────────────────────────
 *
 * {@see hash()} covers the chunker's own {@see VERSION} as well as the text, so
 * changing how articles are cut marks every article stale and re-indexes it,
 * rather than leaving old-shape vectors beside new-shape ones. It is computed
 * over *normalised* text, so a whitespace-only edit is not a content change and
 * does not cost a round of provider calls.
 */
class KnowledgeChunker
{
    /** Bump when the way an article is cut changes, so every article re-indexes. */
    public const VERSION = 1;

    /** Body characters per chunk, excluding the context header. */
    public const MAX_CHARS = 1200;

    /** Characters carried forward between chunks of one section. */
    public const OVERLAP_CHARS = 150;

    /**
     * The article's non-empty sections, in reading order.
     *
     * @return list<array{label: string, text: string}>
     */
    public function sections(AiKnowledgeArticle $article): array
    {
        $candidates = [
            ['Category', $article->category],
            ['Problem', $article->problem_signature],
            ['Cause', $article->root_cause],
            ['Solution', $article->verified_solution],
        ];

        $sections = [];

        foreach ($candidates as [$label, $text]) {
            $normalised = $this->normalise((string) $text);

            if ($normalised !== '') {
                $sections[] = ['label' => $label, 'text' => $normalised];
            }
        }

        return $sections;
    }

    /**
     * The content hash of an article as it will be embedded.
     */
    public function hash(AiKnowledgeArticle $article): string
    {
        return hash('sha256', (string) json_encode([
            'chunker' => static::VERSION,
            'title' => $this->normalise((string) $article->title),
            'sections' => $this->sections($article),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return list<KnowledgeChunk>
     */
    public function chunk(AiKnowledgeArticle $article): array
    {
        $title = $this->normalise((string) $article->title);
        $sections = $this->sections($article);

        // An article with a title and nothing else is still worth finding by
        // its title: one chunk, rather than none and a permanently un-indexable
        // row.
        if ($sections === []) {
            return [$this->make(0, $title, 'Title', '')];
        }

        $chunks = [];

        foreach ($sections as $section) {
            foreach ($this->split($section['text']) as $body) {
                $chunks[] = $this->make(count($chunks), $title, $section['label'], $body);
            }
        }

        return $chunks;
    }

    private function make(int $index, string $title, string $section, string $body): KnowledgeChunk
    {
        $header = $section === 'Title' ? $title : "{$title} — {$section}";
        $text = $body === '' ? $header : "{$header}\n{$body}";

        return new KnowledgeChunk(
            index: $index,
            text: $text,
            hash: hash('sha256', $text),
            tokenEstimate: (int) ceil(mb_strlen($text) / 4),
            section: $section,
        );
    }

    /**
     * Split one section's text into bodies of at most {@see MAX_CHARS}.
     *
     * @return list<string>
     */
    private function split(string $text): array
    {
        if (mb_strlen($text) <= self::MAX_CHARS) {
            return [$text];
        }

        $bodies = [];
        $current = '';

        foreach ($this->units($text) as $unit) {
            $joined = $current === '' ? $unit['text'] : $current.$unit['gap'].$unit['text'];

            if (mb_strlen($joined) <= self::MAX_CHARS) {
                $current = $joined;

                continue;
            }

            if ($current !== '') {
                $bodies[] = $current;
            }

            // Open the next chunk with the tail of the one just closed — unless
            // the overlap plus this unit would itself overrun the limit, in
            // which case the overlap is dropped rather than the unit cut.
            $overlap = $current === '' ? '' : $this->overlapFrom($current);
            $joined = $overlap === '' ? $unit['text'] : $overlap.$unit['gap'].$unit['text'];

            $current = mb_strlen($joined) <= self::MAX_CHARS ? $joined : $unit['text'];
        }

        if ($current !== '') {
            $bodies[] = $current;
        }

        return $bodies;
    }

    /**
     * The section as a list of units none longer than {@see MAX_CHARS}, each with
     * the separator that belongs before it when it follows other text in a
     * chunk: paragraphs stay paragraphs, and a sentence continues the sentence
     * before it.
     *
     * Paragraphs are used whole where they fit; a paragraph that does not is
     * broken into sentences, and a sentence that does not into words, and a
     * word that does not into characters.
     *
     * @return list<array{text: string, gap: string}>
     */
    private function units(string $text): array
    {
        $units = [];

        foreach (preg_split('/\n{2,}/u', $text) ?: [] as $paragraph) {
            $paragraph = trim($paragraph);

            if ($paragraph === '') {
                continue;
            }

            if (mb_strlen($paragraph) <= self::MAX_CHARS) {
                $units[] = ['text' => $paragraph, 'gap' => "\n\n"];

                continue;
            }

            $first = true;

            foreach (preg_split('/(?<=[.!?])\s+/u', $paragraph) ?: [] as $sentence) {
                $sentence = trim($sentence);

                if ($sentence === '') {
                    continue;
                }

                foreach ($this->breakLong($sentence) as $piece) {
                    $units[] = ['text' => $piece, 'gap' => $first ? "\n\n" : ' '];
                    $first = false;
                }
            }
        }

        return $units;
    }

    /**
     * @return list<string>
     */
    private function breakLong(string $sentence): array
    {
        if (mb_strlen($sentence) <= self::MAX_CHARS) {
            return [$sentence];
        }

        $pieces = [];
        $current = '';

        foreach (preg_split('/\s+/u', $sentence) ?: [] as $word) {
            // A single "word" longer than the limit (a pasted hash, a long URL)
            // is cut by characters: dropping it would lose text, and refusing
            // the article would make it un-indexable.
            foreach (mb_str_split($word, self::MAX_CHARS) as $part) {
                $candidate = $current === '' ? $part : "{$current} {$part}";

                if (mb_strlen($candidate) <= self::MAX_CHARS) {
                    $current = $candidate;

                    continue;
                }

                if ($current !== '') {
                    $pieces[] = $current;
                }

                $current = $part;
            }
        }

        if ($current !== '') {
            $pieces[] = $current;
        }

        return $pieces;
    }

    /**
     * The tail of a finished chunk, cut at a word, to open the next one.
     */
    private function overlapFrom(string $body): string
    {
        if (mb_strlen($body) <= self::OVERLAP_CHARS) {
            return '';
        }

        $tail = mb_substr($body, -self::OVERLAP_CHARS);
        $space = mb_strpos($tail, ' ');

        // Cut at the first word boundary so the overlap never begins mid-word.
        return $space === false ? '' : ltrim(mb_substr($tail, $space + 1));
    }

    /**
     * Whitespace-normalised text: line endings unified, runs of blanks
     * collapsed, more than one blank line reduced to one, and the ends trimmed.
     * Meaning-preserving on purpose — it must never alter a word.
     */
    private function normalise(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[^\S\n]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/ ?\n ?/u', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }
}
