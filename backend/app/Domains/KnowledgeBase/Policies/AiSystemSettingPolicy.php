<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Policies;

use App\Domains\KnowledgeBase\Services\AiAdministrationAccess;
use App\Models\AiSystemSetting;
use App\Models\User;

/**
 * Authorization for the AI administration surface (WP-O) — Administrator-only,
 * closed by the policy and not by the `ai.configure` permission string. See
 * {@see AiAdministrationAccess}.
 *
 * `viewAny` and `manage` answer identically: there is no read-only tier of AI
 * configuration, because reading it (which model, what data leaves) is the
 * transparency FR-AI-033 gives the Administrator and nobody else.
 */
class AiSystemSettingPolicy
{
    public function __construct(private readonly AiAdministrationAccess $access) {}

    public function viewAny(User $actor): bool
    {
        return $this->access->canConfigure($actor);
    }

    /** Accepts a class name or an instance. */
    public function manage(User $actor, ?AiSystemSetting $settings = null): bool
    {
        return $this->access->canConfigure($actor);
    }
}
