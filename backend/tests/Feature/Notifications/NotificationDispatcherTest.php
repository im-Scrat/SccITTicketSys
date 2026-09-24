<?php

declare(strict_types=1);

use App\Domains\Administration\Notifications\ProjectNotification;
use App\Domains\Administration\Services\NotificationDispatcher;
use App\Enums\NotificationTopic;
use App\Enums\UserStatus;
use App\Models\Notification as NotificationRecord;
use App\Models\User;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * WP-2.7a — **the dispatcher**, which exists to hold one guarantee
 * (SRS §9 queue design; WP-2.7a non-functional scope).
 *
 * > Nothing about notifying anybody may change the outcome of the thing that
 * > caused the notification.
 *
 * A ticket assignment that succeeded must not become a 500 because Redis is
 * down, and a rolled-back submission must not announce itself. The tests below
 * are the ones that would catch that guarantee being lost, and several of them
 * exercise failure paths that are otherwise only reachable in an outage.
 */
beforeEach(function (): void {
    seedRbac();
    $this->dispatcher = app(NotificationDispatcher::class);
});

function bareNotification(): ProjectNotification
{
    return new class extends ProjectNotification
    {
        public function topic(): NotificationTopic
        {
            return NotificationTopic::MaintenanceScheduled;
        }

        public function payload(User $notifiable): array
        {
            return ['title' => 'Something happened', 'message' => null, 'data' => [], 'action_url' => null];
        }
    };
}

/* ------------------------------------------------------ recipient filtering */

it('never notifies the actor of their own action', function (): void {
    $actor = userWithRole('administrator');
    $other = userWithRole('technician');

    $sent = $this->dispatcher->send([$actor, $other], bareNotification(), $actor);

    expect($sent)->toBe(1)
        ->and(NotificationRecord::query()->pluck('user_id')->all())->toBe([$other->id]);
});

it('collapses the same person arriving twice into one notification', function (): void {
    $user = userWithRole('technician');

    // The reporter who is also the assignee — two relations, one person, and
    // deliberately two separate model instances.
    $sent = $this->dispatcher->send([$user, User::query()->find($user->id)], bareNotification());

    expect($sent)->toBe(1)
        ->and(NotificationRecord::query()->count())->toBe(1);
});

it('drops nulls rather than making every trigger null-check first', function (): void {
    $user = userWithRole('technician');

    expect($this->dispatcher->send([null, $user, null], bareNotification()))->toBe(1);
});

it('does not accumulate notifications on an account that cannot sign in', function (): void {
    $suspended = userWithRole('technician', ['status' => UserStatus::Suspended->value]);
    $active = userWithRole('technician');

    $sent = $this->dispatcher->send([$suspended, $active], bareNotification());

    expect($sent)->toBe(1)
        ->and(NotificationRecord::query()->pluck('user_id')->all())->toBe([$active->id]);
});

/* ------------------------------------------------------- transaction safety */

/**
 * The property every write path in the application now relies on: an event may
 * be raised from inside a transaction, and delivery waits for the commit.
 */
it('waits for the commit before delivering', function (): void {
    $user = userWithRole('technician');

    DB::transaction(function () use ($user): void {
        $this->dispatcher->sendTo($user, bareNotification());

        // Still nothing: the delivery is queued behind this transaction.
        expect(NotificationRecord::query()->count())->toBe(0);
    });

    expect(NotificationRecord::query()->count())->toBe(1);
});

it('announces nothing when the transaction that caused it rolls back', function (): void {
    $user = userWithRole('technician');

    try {
        DB::transaction(function () use ($user): void {
            $this->dispatcher->sendTo($user, bareNotification());

            throw new RuntimeException('the business action failed');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(NotificationRecord::query()->count())->toBe(0);
});

/* --------------------------------------------------------- failure isolation */

/**
 * The outage case, forced rather than waited for.
 *
 * A queue that throws on every push stands in for Redis being unreachable. The
 * caller must not see the exception, and the durable half of the notification —
 * the in-app row the notification centre reads — must still be written.
 */
it('keeps working when the queue cannot take the job', function (): void {
    $user = userWithRole('technician');
    Log::spy();

    $this->app->extend(QueueFactory::class, fn (): object => new class implements QueueFactory
    {
        public function connection($name = null)
        {
            throw new RuntimeException('Connection refused [tcp://redis:6379]');
        }

        public function driver($name = null)
        {
            throw new RuntimeException('Connection refused [tcp://redis:6379]');
        }

        public function extend($driver, $resolver) {}
    });

    $sent = $this->dispatcher->sendTo($user, bareNotification());

    expect($sent)->toBe(1)
        // The business action's caller saw no exception, and the row is there.
        ->and(NotificationRecord::query()->where('user_id', $user->id)->count())->toBe(1);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => str_contains($message, 'in-app row written synchronously'));
});

/**
 * The fallback respects the preference gate. An outage is not licence to write
 * a row into a centre the user asked to be kept out of.
 */
it('does not use an outage as an excuse to ignore a preference', function (): void {
    $user = userWithRole('technician');

    app(App\Domains\Administration\Services\NotificationPreferences::class)->set(
        $user,
        App\Enums\NotificationChannel::InApp,
        NotificationTopic::MaintenanceScheduled->type(),
        false,
    );

    $this->app->extend(QueueFactory::class, fn (): object => new class implements QueueFactory
    {
        public function connection($name = null)
        {
            throw new RuntimeException('Connection refused');
        }

        public function driver($name = null)
        {
            throw new RuntimeException('Connection refused');
        }

        public function extend($driver, $resolver) {}
    });

    $this->dispatcher->sendTo($user, bareNotification());

    expect(NotificationRecord::query()->count())->toBe(0);
});

/* ------------------------------------------------------------- idempotency */

it('fixes the idempotency key at dispatch, so a retry writes nothing new', function (): void {
    $user = userWithRole('technician');
    $notification = bareNotification();

    $key = $notification->dedupeKey();

    // The same object delivered twice is one dispatch retried, not two events.
    $this->dispatcher->sendTo($user, $notification);
    $this->dispatcher->sendTo($user, $notification);

    expect($notification->dedupeKey())->toBe($key)
        ->and(NotificationRecord::query()->count())->toBe(1);
});

it('treats two separate dispatches as two things that happened', function (): void {
    $user = userWithRole('technician');

    $this->dispatcher->sendTo($user, bareNotification());
    $this->dispatcher->sendTo($user, bareNotification());

    expect(NotificationRecord::query()->count())->toBe(2);
});

it('survives being handed an empty recipient list', function (): void {
    expect($this->dispatcher->send([], bareNotification()))->toBe(0)
        ->and($this->dispatcher->sendTo(null, bareNotification()))->toBe(0);
});
