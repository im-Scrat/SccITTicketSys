<?php

declare(strict_types=1);

namespace App\Domains\Assets\Exceptions;

use RuntimeException;

/**
 * A catalog entry that cannot be created as approved (Phase 2.10 R6): its
 * manufacturer, component or model exists only archived, or the approving
 * administrator may no longer add to the catalog. `reason` is a stable code.
 */
final class CatalogEntryBlocked extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("Catalog entry blocked: {$reason}");
    }
}
