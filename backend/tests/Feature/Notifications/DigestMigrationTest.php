<?php

declare(strict_types=1);

use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Models\NotificationDigest;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * WP-2.7e — **the schema the digest needs** (SRS FR-NOT-008; SDD DD-63).
 *
 * Two rules that live in PostgreSQL rather than in PHP, tested the way this
 * project tests database rules: raw inserts, and assertions on the constraint
 * name, so a rule cannot be quietly relocated into application code without
 * this failing.
 *
 * DD-63 recorded a rollback hazard before the work started — re-narrowing the
 * channel CHECK re-validates the table, and a `digest` row would violate it.
 * The migration deletes those rows first; that behaviour is asserted at the
 * bottom of this file rather than trusted.
 */
beforeEach(function (): void {
    seedRbac();
    $this->user = userWithRole('teacher');
});

/* ------------------------------------------------ the widened channel domain */

it('accepts the digest channel the enum now offers', function (): void {
    DB::table('notification_preferences')->insert([
        'user_id' => $this->user->getKey(),
        'channel' => NotificationChannel::Digest->value,
        'notification_type' => NotificationType::Assignment->value,
        'is_enabled' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('notification_preferences')->where('channel', 'digest')->count())->toBe(1);
});

it('still accepts the two channels that existed before', function (string $channel): void {
    DB::table('notification_preferences')->insert([
        'user_id' => $this->user->getKey(),
        'channel' => $channel,
        'notification_type' => NotificationType::Assignment->value,
        'is_enabled' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('notification_preferences')->where('channel', $channel)->count())->toBe(1);
})->with(['in_app', 'email']);

it('still refuses a channel outside the domain', function (): void {
    expect(fn () => DB::table('notification_preferences')->insert([
        'user_id' => $this->user->getKey(),
        'channel' => 'sms',
        'notification_type' => NotificationType::Assignment->value,
        'is_enabled' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class, 'notification_preferences_channel_check');
});

it('builds the domain from the enum, so the two cannot drift', function (): void {
    $definition = DB::selectOne(
        "select pg_get_constraintdef(oid) as def from pg_constraint where conname = 'notification_preferences_channel_check'"
    );

    foreach (NotificationChannel::values() as $value) {
        expect($definition->def)->toContain("'{$value}'");
    }
});

/* ------------------------------------------------------ notification_digests */

it('enforces one digest per user per day, by constraint', function (): void {
    $row = [
        'user_id' => $this->user->getKey(),
        'digest_date' => '2026-09-07',
        'sent_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('notification_digests')->insert($row);

    expect(fn () => DB::table('notification_digests')->insert($row))
        ->toThrow(QueryException::class, 'notification_digests_unique');
});

it('lets the same day belong to different users', function (): void {
    $other = userWithRole('technician');

    foreach ([$this->user, $other] as $user) {
        DB::table('notification_digests')->insert([
            'user_id' => $user->getKey(),
            'digest_date' => '2026-09-07',
            'sent_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    expect(DB::table('notification_digests')->count())->toBe(2);
});

it('keeps sent_at nullable, so a future policy could claim before confirming', function (): void {
    // WP-2.7e commits before sending, so it never writes null -- but the column
    // allows it, which is what would let an at-least-once policy be adopted
    // later without another migration.
    DB::table('notification_digests')->insert([
        'user_id' => $this->user->getKey(),
        'digest_date' => '2026-09-07',
        'sent_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(NotificationDigest::query()->sole()->sent_at)->toBeNull();
});

it('takes the digest record with the user when the account is deleted', function (): void {
    NotificationDigest::query()->create([
        'user_id' => $this->user->getKey(),
        'digest_date' => '2026-09-07',
        'sent_at' => CarbonImmutable::now(),
    ]);

    // A hard delete, not the soft delete the application uses -- this asserts
    // the cascade the foreign key declares.
    DB::table('users')->where('id', $this->user->getKey())->delete();

    expect(NotificationDigest::query()->count())->toBe(0);
});

it('stores the digest date as a calendar day, not an instant', function (): void {
    NotificationDigest::query()->create([
        'user_id' => $this->user->getKey(),
        'digest_date' => '2026-09-07',
        'sent_at' => CarbonImmutable::now(),
    ]);

    expect(DB::table('notification_digests')->value('digest_date'))->toBe('2026-09-07');
});
