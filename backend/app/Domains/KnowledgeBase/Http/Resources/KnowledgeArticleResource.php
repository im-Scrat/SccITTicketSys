<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Http\Resources;

use App\Models\AiKnowledgeArticle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One knowledge article. `detail` adds the body; the list view carries a short
 * excerpt only. Creator identity is exposed as a display name for staff and never
 * as an email or id.
 *
 * @mixin AiKnowledgeArticle
 */
class KnowledgeArticleResource extends JsonResource
{
    public function __construct($resource, private readonly bool $detail = false)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $isStaff = $viewer !== null && in_array($viewer->role?->slug, ['administrator', 'technician'], true);

        return [
            'id' => $this->uuid,
            'title' => $this->title,
            'slug' => $this->slug,
            'category' => $this->category,
            'status' => $this->status->value,
            'excerpt' => $this->when(! $this->detail, fn () => mb_substr((string) ($this->verified_solution ?? $this->problem_signature), 0, 220)),
            'problem_signature' => $this->when($this->detail, $this->problem_signature),
            'root_cause' => $this->when($this->detail, $this->root_cause),
            'verified_solution' => $this->when($this->detail, $this->verified_solution),
            'verification_count' => $this->verification_count,
            'published_at' => $this->published_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'author' => $this->when($isStaff, fn () => $this->createdBy?->fullName()),
            'from_ticket' => $this->when($isStaff && $this->createdFromTicket !== null, fn () => $this->createdFromTicket?->ticket_number),
        ];
    }
}
