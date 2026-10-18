<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

/**
 * A row's readiness, derived from its issues in this order: any error →
 * `invalid`; a duplicate → `duplicate`; an unresolved review issue →
 * `needs_review`; otherwise `ready`. `excluded` is the administrator's own
 * decision and wins over all of them (Phase 2.10 R6, Appendix B.5).
 */
enum AssetImportRowStatus: string
{
    use HasValues;

    case Ready = 'ready';
    case NeedsReview = 'needs_review';
    case Invalid = 'invalid';
    case Duplicate = 'duplicate';
    case Excluded = 'excluded';

    public function label(): string
    {
        return match ($this) {
            self::Ready => 'Ready',
            self::NeedsReview => 'Needs a decision',
            self::Invalid => 'Invalid',
            self::Duplicate => 'Duplicate',
            self::Excluded => 'Excluded',
        };
    }
}
