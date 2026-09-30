<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\DTOs;

/**
 * One embeddable slice of a knowledge article (WP-P).
 *
 * Self-contained on purpose: `text` opens with the article's title and the
 * section it came from, so a chunk retrieved on its own still says what it is
 * about — a bare paragraph of "replace the unit" retrieved with no context is a
 * useless citation.
 */
final readonly class KnowledgeChunk
{
    public function __construct(
        public int $index,
        public string $text,
        /** sha256 of `text` — stored per chunk in `ai_embeddings.content_hash`. */
        public string $hash,
        /** A budgeting estimate, never a billing figure. */
        public int $tokenEstimate,
        /** The article section the body came from (`Problem`, `Solution`, …). */
        public string $section,
    ) {}
}
