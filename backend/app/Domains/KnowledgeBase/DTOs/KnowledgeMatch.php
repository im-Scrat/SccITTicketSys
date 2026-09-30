<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\DTOs;

/**
 * One knowledge article the retriever found, with the chunk that matched
 * (WP-P; SRS FR-AI-007 — "cite the source records used").
 *
 * Identified by the article's public uuid and slug only. A numeric id never
 * leaves the retrieval layer, because whatever consumes this next (the
 * assistant, a citation in a reply) is a surface that must not be able to name
 * an internal key.
 */
final readonly class KnowledgeMatch
{
    public function __construct(
        public string $articleUuid,
        public string $slug,
        public string $title,
        public ?string $category,
        public int $chunkIndex,
        public string $content,
        /** Cosine similarity in [-1, 1]; higher is closer. */
        public float $similarity,
    ) {}
}
