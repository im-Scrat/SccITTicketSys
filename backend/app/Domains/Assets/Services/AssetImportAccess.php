<?php

declare(strict_types=1);

namespace App\Domains\Assets\Services;

use App\Models\User;

/**
 * The single answer to "may this user import assets?" (Phase 2.10 R6, SDD
 * DD-96).
 *
 * **Administrator-only, with no new permission.** Importing creates PC units,
 * assets and catalog entries in bulk, so it needs what creating one needs
 * (`assets.view` + `assets.create`, Administrator-only in the §8.4 matrix) and
 * the Administrator **role** as well: `Gate::before` opens any ability whose
 * name equals a permission the user holds, and permissions can be granted per
 * user (FR-USER-010), so a bare `can:assets.create` would let a deputised
 * technician bulk-load the register. The routes use the dotless abilities of
 * `AssetImportPolicy`, which never match a permission name, and those delegate
 * here — as do the queued jobs, which re-check the confirming administrator
 * before and during the save.
 */
final class AssetImportAccess
{
    public function canUse(User $actor): bool
    {
        return $actor->isAdministrator()
            && $actor->hasPermissionTo('assets.view')
            && $actor->hasPermissionTo('assets.create');
    }
}
