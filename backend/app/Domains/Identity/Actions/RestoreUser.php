<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Services\AuditLogger;
use App\Enums\ActivityAction;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Restore a previously archived (soft-deleted) user (SRS FR-USER-006). The
 * account returns in whatever status it held when archived; no privileges are
 * implicitly re-granted. Stamps `updated_by` and audits the restoration.
 */
class RestoreUser
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $user, User $admin, Request $request): User
    {
        $user->restore();
        $user->forceFill(['updated_by' => $admin->getKey()])->save();

        $this->audit->activity(
            ActivityAction::UserRestored,
            actor: $admin,
            subject: $user,
            request: $request,
            description: 'User account restored',
        );

        return $user->load('role');
    }
}
