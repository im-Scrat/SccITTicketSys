<?php

declare(strict_types=1);

use App\Domains\Administration\Notifications\ProjectNotification;
use App\Domains\Analytics\Notifications\ReportEmailNotification;
use App\Domains\Identity\Notifications\AdminMessage;
use App\Domains\Identity\Notifications\QueuedResetPassword;
use App\Domains\Identity\Notifications\RegistrationApproved;
use App\Domains\Identity\Notifications\RegistrationRejected;
use App\Domains\Identity\Notifications\RegistrationSubmitted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

/**
 * The account emails used to be sent on the request thread: with a real relay,
 * an outage became a 500 after the database change had committed, and a bulk
 * message could go out from inside a transaction that then rolled back. They
 * are now queued after commit, with the same retry policy as every other mail.
 */
beforeEach(fn () => seedRbac());

dataset('account mails', [
    'registration submitted' => [fn () => new RegistrationSubmitted],
    'registration approved' => [fn () => new RegistrationApproved],
    'registration rejected' => [fn () => new RegistrationRejected('duplicate')],
    'admin message' => [fn () => new AdminMessage('Subject', ['Line'])],
    'password reset' => [fn () => new QueuedResetPassword('token')],
]);

it('queues every account email after commit, on the notifications queue, with backoff', function (Closure $make) {
    $notification = $make();

    expect($notification)->toBeInstanceOf(ShouldQueueAfterCommit::class)
        ->and($notification->viaQueues())->toBe(['mail' => ProjectNotification::NOTIFICATIONS_QUEUE])
        ->and($notification->tries)->toBe(3)
        ->and($notification->backoff())->toBe([60, 300, 900]);
})->with('account mails');

it('gives the other mail notifications the same retry policy', function () {
    $report = (new ReflectionClass(ReportEmailNotification::class))->newInstanceWithoutConstructor();

    expect($report)->toBeInstanceOf(ShouldQueue::class)
        ->and($report->backoff())->toBe([60, 300, 900])
        ->and($report->tries)->toBe(3)
        ->and(method_exists(ProjectNotification::class, 'backoff'))->toBeTrue();
});

it('answers forgot-password at once and leaves the email to the queue', function () {
    Queue::fake();
    userWithRole('teacher', ['email' => 'queued-reset@example.org']);

    $this->postJson('/api/forgot-password', ['email' => 'queued-reset@example.org'])->assertOk();

    Queue::assertPushedOn(ProjectNotification::NOTIFICATIONS_QUEUE, SendQueuedNotifications::class,
        fn (SendQueuedNotifications $job) => $job->notification instanceof QueuedResetPassword);
});

it('answers a registration at once and leaves the confirmation to the queue', function () {
    Queue::fake();

    $this->postJson('/api/register', [
        'first_name' => 'Queued',
        'last_name' => 'Applicant',
        'email' => 'queued-applicant@example.org',
        'role' => 'teacher',
        'password' => 'Str0ng-Passw0rd!',
        'password_confirmation' => 'Str0ng-Passw0rd!',
    ])->assertCreated();

    Queue::assertPushedOn(ProjectNotification::NOTIFICATIONS_QUEUE, SendQueuedNotifications::class,
        fn (SendQueuedNotifications $job) => $job->notification instanceof RegistrationSubmitted);
});

it('sends nothing for a message whose transaction rolled back, and sends it once a transaction commits', function () {
    // The real (sync) queue, not Queue::fake(): the fake records a job the
    // moment it is pushed and cannot show the after-commit deferral.
    $user = userWithRole('teacher');
    $sent = fn (): array => collect(Mail::mailer()->getSymfonyTransport()->messages())
        ->map(fn ($m) => $m->getOriginalMessage()->getSubject())
        ->all();

    try {
        DB::transaction(function () use ($user): void {
            $user->notify(new AdminMessage('Rolled back', ['Line']));

            throw new RuntimeException('the bulk action failed after notifying');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect($sent())->toBe([]);

    DB::transaction(fn () => $user->notify(new AdminMessage('Committed', ['Line'])));

    expect($sent())->toBe(['Committed']);
});
