<?php

declare(strict_types=1);

namespace App\Domains\Administration\Http\Resources;

use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One notification, as the WP-2.7b notification centre will read it
 * (SRS FR-NOT-001/004/005).
 *
 * Every row this resource can be handed already belongs to the caller — the
 * controller scopes on `user_id` and the policy re-asserts it per record — so
 * there is no redaction to perform here. What the resource does instead is
 * refuse to widen the surface: the internal `id`, the `user_id` and the
 * `dedupe_key` never leave the application.
 *
 * The `dedupe_key` omission is the one worth naming. It is an *idempotency*
 * value, and several of them are structured — `ticket.sla_threshold:{uuid}:
 * {stage}` names a ticket the recipient can already see, but publishing the
 * scheme would hand a client the ability to reason about notifications that
 * were suppressed rather than sent. It is a server-side concern and it stays
 * one.
 *
 * ── Why the jsonb column is exposed as `payload`, not `data` ───────────────
 *
 * The column is called `data`, and naming the field to match was the obvious
 * choice — and wrong. `JsonResource` wraps its output under `data`, but
 * `haveDefaultWrapperAndDataIsUnwrapped()` **skips that wrapper when the array
 * already has a `data` key**. So a single notification came back unwrapped
 * (`{"id": …, "data": {…}}`) while a list came back wrapped
 * (`{"data": [{…}]}`) — two different shapes for one resource, from one
 * accidental name collision. Renaming the field costs nothing and removes an
 * ambiguity WP-2.7b would otherwise have had to discover for itself.
 *
 * @mixin Notification
 */
class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'type' => $this->type->value,
            // The trigger identity, for grouping and per-trigger icons. Lives in
            // `data` because the nine-value `type` column cannot express it.
            'topic' => $this->data['topic'] ?? null,
            'title' => $this->title,
            'message' => $this->message,
            'payload' => $this->data ?? [],
            'action_url' => $this->action_url,
            'is_read' => $this->read_at !== null,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
