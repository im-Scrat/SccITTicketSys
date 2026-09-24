<?php

declare(strict_types=1);

namespace App\Domains\Administration\Http\Resources;

use App\Models\Announcement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One announcement, in the two shapes it is read in (SRS FR-NOT-010/011).
 *
 * ── The internal key never leaves ─────────────────────────────────────────
 *
 * `id` on the wire is the **uuid**, as everywhere else in this API. The bigint
 * primary key and `created_by`'s raw foreign key stay behind: a reader has no
 * use for either, and publishing them would let a client count announcements it
 * cannot read.
 *
 * ── Management fields are conditional, not filtered client-side ───────────
 *
 * A reader gets the announcement. A manager additionally gets `is_active`, the
 * author and the timestamps, because those are the fields the management
 * surface edits. The distinction is drawn here with `when()` rather than by
 * shipping everything and hiding some of it, so a reader's payload structurally
 * cannot contain the draft state of announcements they were never shown.
 */
class AnnouncementResource extends JsonResource
{
    /** @var Announcement */
    public $resource;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $manages = $user !== null && $user->hasPermissionTo('system.announcements.manage');

        return [
            'id' => $this->resource->uuid,
            'title' => $this->resource->title,
            'content' => $this->resource->content,
            'audience' => $this->resource->audience->value,
            'is_pinned' => (bool) $this->resource->is_pinned,
            'starts_at' => $this->resource->starts_at?->toIso8601String(),
            'ends_at' => $this->resource->ends_at?->toIso8601String(),
            'created_at' => $this->resource->created_at?->toIso8601String(),

            // Management-only projection.
            'is_active' => $this->when($manages, fn (): bool => (bool) $this->resource->is_active),
            'updated_at' => $this->when(
                $manages,
                fn (): ?string => $this->resource->updated_at?->toIso8601String(),
            ),
            'created_by' => $this->when($manages, function (): ?array {
                $author = $this->resource->createdBy;

                return $author === null ? null : [
                    'id' => $author->uuid,
                    'name' => $author->fullName(),
                ];
            }),
        ];
    }
}
