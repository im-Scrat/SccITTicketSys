<?php

declare(strict_types=1);

namespace App\Domains\Assets\Actions;

use App\Domains\Assets\Services\AssetLifecycle;
use App\Enums\AssetStatus;
use App\Models\Asset;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Move an asset to a new lifecycle status (SRS FR-AST-005).
 *
 * A thin seam over {@see AssetLifecycle}, kept for symmetry with the other
 * Actions so every controller in this domain talks to the same layer. The
 * service owns the transition rules, the history row and the audit entry —
 * putting them here would split the invariant across two classes and let a
 * future caller bypass it by reaching for the service directly.
 */
class ChangeAssetStatus
{
    public function __construct(private readonly AssetLifecycle $lifecycle) {}

    public function handle(
        Asset $asset,
        AssetStatus $target,
        User $actor,
        Request $request,
        ?string $reason = null,
    ): Asset {
        return $this->lifecycle->transition($asset, $target, $actor, $reason, $request);
    }
}
