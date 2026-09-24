<?php

declare(strict_types=1);

namespace App\Domains\Administration\Services;

use App\Enums\AnnouncementAudience;
use App\Models\Announcement;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * **Who may read which announcement** (SRS FR-NOT-010/011; WP-2.7c).
 *
 * The row-scoping service for announcements, built on the shape DD-40
 * established for tickets and DD-55 repeated for maintenance: **one rule,
 * consulted by the policy *and* by every query object.** That is what makes the
 * guarantee provable rather than asserted — a list filter and a single-record
 * read that share a definition cannot disagree, so an announcement absent from
 * a reader's list is unreachable by its uuid too.
 *
 * ── Audience is an authorization boundary, not a display filter ────────────
 *
 * This is the distinction the Client's authorization directive names
 * explicitly — "a user outside the announcement audience must also be unable
 * to retrieve the announcement directly by UUID/API" — and it is the reason
 * this service exists at all rather than the audience being a
 * `where` clause copied into two controllers. An announcement targeted at
 * technicians is not merely *hidden* from teachers: a teacher who guesses its
 * uuid is refused. Hiding is a convenience; the refusal is the control.
 *
 * ── Reading is not the same as managing ────────────────────────────────────
 *
 * An administrator holding `system.announcements.manage` sees every
 * announcement in the management surface — including drafts, expired ones and
 * announcements addressed to other audiences — because managing them requires
 * it. The **reader** surface is audience-scoped for everybody, administrators
 * included: an administrator reading their own announcements list should see
 * what an administrator was told, not the estate. Two questions, two methods.
 */
class AnnouncementVisibility
{
    /** The audience bucket a user falls into, from their role. */
    public function audienceFor(User $user): AnnouncementAudience
    {
        return match ($user->role?->slug) {
            'administrator' => AnnouncementAudience::Admins,
            'technician' => AnnouncementAudience::Technicians,
            default => AnnouncementAudience::Teachers,
        };
    }

    /**
     * Announcements this user may **read**: live, in-window, and addressed to
     * them or to everyone.
     *
     * `is_active` is the publication switch, and the window is inclusive of
     * nulls at both ends — an announcement with no `starts_at` has always
     * applied, and one with no `ends_at` does not expire. That matches the
     * baselined schema, where both columns are nullable and only their
     * *ordering* is constrained.
     *
     * @param  Builder<Announcement>  $query
     * @return Builder<Announcement>
     */
    public function scopeReadable(Builder $query, User $user): Builder
    {
        $now = now();

        return $query
            ->where('is_active', true)
            ->whereIn('audience', [
                AnnouncementAudience::All->value,
                $this->audienceFor($user)->value,
            ])
            ->where(fn (Builder $inner): Builder => $inner
                ->whereNull('starts_at')
                ->orWhere('starts_at', '<=', $now))
            ->where(fn (Builder $inner): Builder => $inner
                ->whereNull('ends_at')
                ->orWhere('ends_at', '>=', $now));
    }

    /**
     * May this user read this specific announcement?
     *
     * The single-record half of {@see scopeReadable()}, and deliberately the
     * same rule expressed once more rather than a second opinion: the policy
     * calls this, the reader endpoint calls the scope, and a divergence between
     * them would be an IDOR.
     */
    public function canRead(User $user, Announcement $announcement): bool
    {
        if (! $announcement->is_active) {
            return false;
        }

        $audience = $announcement->audience;

        if ($audience !== AnnouncementAudience::All && $audience !== $this->audienceFor($user)) {
            return false;
        }

        $now = now();

        if ($announcement->starts_at !== null && $announcement->starts_at->greaterThan($now)) {
            return false;
        }

        if ($announcement->ends_at !== null && $announcement->ends_at->lessThan($now)) {
            return false;
        }

        return true;
    }
}
