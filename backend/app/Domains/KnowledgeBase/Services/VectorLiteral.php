<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Services;

use App\Domains\KnowledgeBase\Exceptions\AiProviderException;

/**
 * The one place an embedding becomes a pgvector literal (WP-P).
 *
 * Validation happens here, at the last moment before persistence or a query,
 * and not only in {@see EmbeddingGenerator}: this is the boundary where a
 * wrongly shaped vector would otherwise surface as a raw Postgres error, and it
 * is also where a non-finite value (NaN, infinity — which a buggy provider or a
 * bad mock can produce and which pgvector rejects only after the round trip)
 * is refused with a safe message.
 */
final class VectorLiteral
{
    /**
     * @param  array<int, mixed>  $vector  provider output — not trusted to be numbers until checked here
     *
     * @throws AiProviderException when the length is not `$dimensions` or any value is not finite
     */
    public static function from(array $vector, int $dimensions): string
    {
        if (count($vector) !== $dimensions) {
            throw AiProviderException::embeddingDimensionMismatch($dimensions, count($vector));
        }

        $parts = [];

        foreach ($vector as $value) {
            if (! is_int($value) && ! is_float($value)) {
                throw AiProviderException::malformedOutput();
            }

            if (! is_finite((float) $value)) {
                throw AiProviderException::malformedOutput();
            }

            // `%.9g` keeps single-precision round-trip accuracy without the
            // trailing zeros `%f` would add to a 768-value literal.
            $parts[] = sprintf('%.9g', $value);
        }

        return '['.implode(',', $parts).']';
    }
}
