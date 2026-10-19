<?php

declare(strict_types=1);

namespace App\Domains\Administration\Http\Requests;

use App\Enums\AnnouncementAudience;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creating or editing an announcement (SRS FR-NOT-010; WP-2.7c).
 *
 * ── What this request may not carry ────────────────────────────────────────
 *
 * There is no `is_active` field and there never will be. Publication is an
 * operation on `ManageAnnouncement`, not a column a client sets, so a `PUT`
 * cannot notify an audience as a side effect of saving a typo fix (WP-2.7c
 * decision D7). There is no `created_by` either — the author is the session.
 *
 * ── Content is plain text (WP-2.7c decision D4) ────────────────────────────────────
 *
 * `content` is a plain string. No HTML, no Markdown, and therefore no
 * sanitisation surface to get wrong: React escapes it on the way out, and the
 * one place an announcement is rendered treats it as prose. The length ceiling
 * is a denial-of-service bound rather than an editorial one.
 *
 * The window is validated here **and** by the database's
 * `announcements_date_order_check`. Both layers matter: this one gives the
 * administrator a 422 naming the field, and the constraint means a seeder, a
 * console command or a future import meets the same wall.
 */
class StoreAnnouncementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'content' => ['required', 'string', 'max:5000'],
            'audience' => ['required', Rule::in(AnnouncementAudience::values())],
            'starts_at' => ['nullable', 'date'],
            // Mirrors the CHECK constraint: an end before its start is not a
            // window, it is a typo.
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'is_pinned' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'audience.in' => 'Choose who this announcement is for.',
            'ends_at.after' => 'The end of the window must come after its start.',
        ];
    }
}
