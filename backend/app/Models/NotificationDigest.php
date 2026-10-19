<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One user's daily digest for one school-local calendar day
 * (SRS FR-NOT-008, SDD DD-63).
 *
 * This row is not a notification and is never shown to anybody. It exists so
 * that "has this user already been sent the digest for this day?" has an answer
 * the database enforces, rather than one a worker has to remember: the unique
 * index on `(user_id, digest_date)` is the exactly-once guarantee, and
 * `queue:work --tries=3` is the reason it has to be.
 *
 * `digest_date` is the day **summarised**, not the day the mail went out. The
 * 07:00 Asia/Manila run on the 8th writes `digest_date = 2026-09-07`.
 *
 * `sent_at` records the moment the send was **committed**, not confirmed
 * delivery. WP-2.7e is deliberately at-most-once (§8 of the approved decisions):
 * the row is written before SMTP is contacted, so a crash mid-send costs that
 * user one day's digest rather than risking a duplicate. Nothing here should
 * ever be read as proof that mail arrived.
 *
 * There is no `uuid`: the project adds one where a record is addressable over
 * the API, and this one is never exposed — the same reasoning that leaves
 * `notification_preferences` without one.
 *
 * @property int $id
 * @property int $user_id
 * @property Carbon $digest_date
 * @property Carbon|null $sent_at
 */
class NotificationDigest extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            // A calendar day, not an instant. Cast as a date so a comparison
            // can never accidentally depend on a time component.
            'digest_date' => 'date',
            'sent_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
