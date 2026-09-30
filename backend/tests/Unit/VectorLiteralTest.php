<?php

declare(strict_types=1);

use App\Domains\KnowledgeBase\Exceptions\AiProviderException;
use App\Domains\KnowledgeBase\Services\VectorLiteral;

/**
 * WP-P — the last check before a vector reaches the `vector(768)` column, and
 * the only place a provider's numbers are turned into SQL text.
 */
it('renders a pgvector literal', function (): void {
    expect(VectorLiteral::from([0.5, -1, 0.25], 3))->toBe('[0.5,-1,0.25]');
});

it('keeps nine significant digits and no trailing zeros', function (): void {
    expect(VectorLiteral::from([1 / 3, 0.1, 1.0e-7], 3))->toBe('[0.333333333,0.1,1.0e-7]')
        ->and(VectorLiteral::from(array_fill(0, 768, 0.0), 768))->toStartWith('[0,0,0');
});

it('refuses a vector of the wrong width, stating both numbers', function (int $count): void {
    expect(fn () => VectorLiteral::from(array_fill(0, $count, 0.1), 768))
        ->toThrow(AiProviderException::class, "returned a {$count}-dimension embedding; this application requires 768");
})->with([0, 3, 767, 769, 3072]);

it('refuses non-finite values', function (float $bad): void {
    expect(fn () => VectorLiteral::from([0.1, $bad], 2))
        ->toThrow(AiProviderException::class, 'empty or malformed');
})->with([NAN, INF, -INF]);

it('refuses anything that is not a number — the literal is interpolated into SQL text', function (mixed $bad): void {
    expect(fn () => VectorLiteral::from([0.1, $bad], 2))->toThrow(AiProviderException::class);
})->with(['a string' => ["0.1'; DROP TABLE ai_embeddings; --"], 'null' => [null], 'array' => [[1]], 'numeric string' => ['0.5'], 'bool' => [true]]);

it('marks only transient provider failures as worth retrying', function (): void {
    expect(AiProviderException::requestFailed()->isTransient())->toBeTrue()
        ->and(AiProviderException::timedOut()->isTransient())->toBeTrue()
        ->and(AiProviderException::rateLimited()->isTransient())->toBeTrue()
        ->and(AiProviderException::malformedOutput()->isTransient())->toBeFalse()
        ->and(AiProviderException::embeddingDimensionMismatch(768, 3)->isTransient())->toBeFalse()
        ->and(AiProviderException::inputTooLarge(10)->isTransient())->toBeFalse();
});
