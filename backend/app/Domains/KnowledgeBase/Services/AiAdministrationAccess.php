<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Services;

use App\Models\User;

/**
 * The single answer to "may this user configure the AI layer?" (WP-O) —
 * **Administrator-only**: which model is active, whether predictions and
 * learning are on, what leaves the system for the provider.
 *
 * The role *and* the permission, for the reason {@see PcPredictionAccess} and
 * `FloorPlanAccess` give at length: `Gate::before` passes any ability whose
 * string equals a permission in the user's set, and a per-user grant of
 * `ai.configure` (FR-USER-010 allows one to anybody) must not be able to open
 * the provider configuration of the whole system to a Technician. Every route
 * in this module goes through the policy abilities, never the permission string.
 */
final class AiAdministrationAccess
{
    public function canConfigure(User $actor): bool
    {
        return $actor->isAdministrator() && $actor->hasPermissionTo('ai.configure');
    }
}
