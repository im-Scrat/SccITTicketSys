<?php

declare(strict_types=1);

namespace App\Domains\Administration\Services;

use App\Enums\AnnouncementAudience;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * **Recipient resolution** for the FR-NOT-003 matrix (WP-2.7a).
 *
 * Two kinds of recipient exist in the approved matrix and they need different
 * treatment, which is why they are both here rather than each being worked out
 * at the trigger that needs them:
 *
 *  - **Specific users** — "the assigned technician", "the reporter". The trigger
 *    already holds the row; all this class does is filter it.
 *  - **Role audiences** — "Administrators", named verbatim by FR-NOT-003 for
 *    the work-support trigger. The membership is a query, and it must be the
 *    *same* query everywhere or two triggers will disagree about who the
 *    administrators are.
 *
 * ── Three filters every recipient list passes ──────────────────────────────
 *
 * **Active accounts only.** A suspended, pending or rejected account must not
 * accumulate notifications: the user cannot sign in to read them, the row would
 * outlive the reason it was written, and mailing a rejected applicant about
 * internal ticket movement would be a disclosure. `SoftDeletes` on `User`
 * already removes archived accounts from every query here.
 *
 * **No duplicates.** A reporter who is also the assigned technician is one
 * person and gets one notification, not two — deduplicated on the primary key
 * rather than on the object, because the same user can arrive as two separate
 * model instances loaded down different relations.
 *
 * **Never the actor.** The person who performed the action does not need to be
 * told they performed it (matrix T2, verbatim: *"the actor is never notified of
 * their own action"*). This is the rule most easily forgotten at a call site and
 * most obvious when it is wrong, so it is applied centrally by
 * {@see except()} rather than trusted to each trigger.
 */
class NotificationAudience
{
    /**
     * Every active administrator.
     *
     * By role slug, not by permission. FR-NOT-003 names the *role* — "a
     * technician work support request submitted (to Administrators)" — and
     * resolving it by permission instead would silently widen the audience the
     * moment a permission is granted per-user, which SDD DD-05 explicitly
     * supports for other reasons.
     *
     * @return Collection<int, User>
     */
    public function administrators(): Collection
    {
        return User::query()
            ->where('status', UserStatus::Active->value)
            ->whereHas('role', fn ($query) => $query->where('slug', 'administrator'))
            ->get();
    }

    /**
     * Every active user in an announcement's audience (FR-NOT-010, T12).
     *
     * By role slug, for the same reason {@see administrators()} is: the
     * `announcements.audience` column names roles — `all`, `teachers`,
     * `technicians`, `admins` — and resolving them by permission instead would
     * let an individually granted ability quietly move somebody into an
     * audience an administrator never targeted.
     *
     * **This is the authorization boundary, not a display filter.** The set
     * returned here is the set that will be told; a user outside it is not
     * merely shown nothing, they are never notified and cannot read the
     * announcement by uuid either (see AnnouncementVisibility).
     *
     * @return Collection<int, User>
     */
    public function forAnnouncementAudience(AnnouncementAudience $audience): Collection
    {
        $query = User::query()->where('status', UserStatus::Active->value);

        if ($audience === AnnouncementAudience::All) {
            return $query->get();
        }

        // `All` returned above, so this match is total over what remains.
        $slug = match ($audience) {
            AnnouncementAudience::Teachers => 'teacher',
            AnnouncementAudience::Technicians => 'technician',
            default => 'administrator',
        };

        return $query
            ->whereHas('role', fn ($inner) => $inner->where('slug', $slug))
            ->get();
    }

    /**
     * Narrow a set of candidate recipients to the ones that should be notified.
     *
     * Nulls are accepted and dropped, because most call sites are working with
     * optional relations — `$ticket->assignedTechnician` is legitimately null on
     * an unassigned ticket, and forcing every trigger to null-check first would
     * put the same three lines in ten places.
     *
     * @param  iterable<User|null>  $candidates
     * @param  User|null  $actor  the person whose action caused this, never notified
     * @return list<User>
     */
    public function except(iterable $candidates, ?User $actor = null): array
    {
        $seen = [];
        $out = [];

        foreach ($candidates as $candidate) {
            if (! $candidate instanceof User) {
                continue;
            }

            $id = (int) $candidate->getKey();

            if ($id === (int) ($actor?->getKey() ?? 0) || isset($seen[$id])) {
                continue;
            }

            if (! $this->isActive($candidate)) {
                // Cannot sign in, so cannot read it — and an email about
                // internal movement to a rejected applicant is a disclosure.
                continue;
            }

            $seen[$id] = true;
            $out[] = $candidate;
        }

        return $out;
    }

    /**
     * Whether this account is active — asked in a way that survives a partially
     * selected model.
     *
     * **This method exists because of a real, silent failure.** Several queries
     * in this application eager-load people with an explicit column list, for
     * good reason: `maintenance:detect-due` loads
     * `technician:id,uuid,first_name,last_name` because those are the only
     * columns it prints. A `User` arriving that way has **no `status`
     * attribute at all**, and a plain `$user->status !== UserStatus::Active`
     * comparison reads the missing attribute as null, decides the account is
     * not active, and drops the recipient — with no error, no log line, and no
     * notification. The sweep would report the overdue visit on the console and
     * tell nobody.
     *
     * So a missing attribute is treated as *unknown* rather than as *inactive*,
     * and answered with one indexed lookup on the primary key. The alternative —
     * assuming active when the column is absent — would notify suspended
     * accounts, which is the failure this filter exists to prevent.
     */
    private function isActive(User $candidate): bool
    {
        if (array_key_exists('status', $candidate->getAttributes())) {
            return $candidate->status === UserStatus::Active;
        }

        /*
         * An existence check rather than reading the column back, because
         * `Builder::value()` hydrates a model and therefore applies the enum
         * cast — so the returned value is a `UserStatus`, not the string it
         * looks like it should be. Comparing it against `->value` fails
         * silently and drops the recipient, which is the very failure this
         * method was written to stop. `where(...)->exists()` compares in
         * Postgres, where there is no cast to be wrong about, and is served by
         * the primary key.
         */
        return User::query()
            ->whereKey($candidate->getKey())
            ->where('status', UserStatus::Active->value)
            ->exists();
    }
}
