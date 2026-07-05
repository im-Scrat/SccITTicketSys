<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Resources;

use App\Enums\ActivityAction;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A single audit-timeline entry (SRS FR-AUD-*). Renders the human label for the
 * action and the actor (uuid + name) when the relation is loaded.
 *
 * @mixin ActivityLog
 */
class ActivityLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'label' => ActivityAction::tryFrom($this->action)?->label() ?? $this->action,
            'description' => $this->description,
            'module' => $this->module,
            'actor' => $this->whenLoaded('user', fn () => $this->user !== null ? [
                'id' => $this->user->uuid,
                'name' => $this->user->fullName(),
            ] : null),
            'properties' => $this->properties,
            'ip_address' => $this->ip_address,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
