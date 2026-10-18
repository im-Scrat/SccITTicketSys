<?php

declare(strict_types=1);

namespace App\Domains\Assets\Policies;

use App\Domains\Assets\Services\AssetImportAccess;
use App\Models\AssetImport;
use App\Models\User;

/**
 * Authorization for asset imports (Phase 2.10 R6, SDD DD-96).
 *
 * Administrator-only; see {@see AssetImportAccess} for why the role is needed
 * as well as the permissions. Dotless abilities, so `Gate::before` can never
 * match them to a permission name: `can:viewAny,AssetImport` closes the whole
 * `/api/admin/asset-imports` group (a test asserts every route carries it) and
 * `can:manage,…` every write.
 *
 * Any authorised administrator may continue, confirm or cancel another
 * administrator's import (plan decision 9.1-12): the ledger records who did
 * each step.
 */
class AssetImportPolicy
{
    public function __construct(private readonly AssetImportAccess $access) {}

    public function viewAny(User $actor): bool
    {
        return $this->access->canUse($actor);
    }

    public function view(User $actor, AssetImport $import): bool
    {
        return $this->access->canUse($actor);
    }

    public function manage(User $actor, ?AssetImport $import = null): bool
    {
        return $this->access->canUse($actor);
    }
}
