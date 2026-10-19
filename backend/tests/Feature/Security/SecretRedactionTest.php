<?php

declare(strict_types=1);

use App\Support\Logging\RedactSecrets;
use App\Support\Logging\RedactSecretsProcessor;
use App\Support\Queue\RedactingFailedJobProvider;
use App\Support\Security\ConfiguredSecrets;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Symfony\Component\Mailer\Exception\TransportException;

/**
 * NFR-SEC-005: this deployment's credentials never reach a log line or the
 * `failed_jobs` table. The fixtures mirror the real leak: Symfony's SMTP
 * authentication failure, whose message embeds the relay username, raised
 * inside a queued mail job.
 *
 * The values below are fake and exist only to be redacted; .gitleaks.toml
 * allowlists this file for that reason.
 */
beforeEach(function () {
    config([
        'mail.mailers.smtp.username' => 'sccit-render-relay-user',
        'mail.mailers.smtp.password' => 'Fake-SMTP-Passw0rd-For-Tests',
        'database.connections.pgsql.password' => 'Fake-Database-Passw0rd-1234',
    ]);
});

function smtpAuthFailure(): TransportException
{
    return new TransportException(
        'Failed to authenticate on SMTP server with username "sccit-render-relay-user" using the following authenticators: "LOGIN", "PLAIN".',
        535,
    );
}

it('lists the SMTP username and password among the configured secrets', function () {
    expect(ConfiguredSecrets::values())
        ->toContain('sccit-render-relay-user')
        ->toContain('Fake-SMTP-Passw0rd-For-Tests')
        ->toContain('Fake-Database-Passw0rd-1234');
});

it('ignores unset and trivially short values, so ordinary words survive', function () {
    config(['mail.mailers.smtp.username' => null, 'mail.mailers.smtp.password' => 'null', 'database.connections.pgsql.password' => 'abc']);

    expect(ConfiguredSecrets::values())->not->toContain('null')->not->toContain('abc');
    expect(ConfiguredSecrets::redact('a null value and abc'))->toBe('a null value and abc');
});

it('removes credentials from the message, the context and a logged exception', function () {
    $record = new LogRecord(
        datetime: new DateTimeImmutable,
        channel: 'test',
        level: Level::Error,
        message: 'relay sccit-render-relay-user refused Fake-SMTP-Passw0rd-For-Tests',
        context: ['exception' => smtpAuthFailure(), 'dsn' => 'pgsql://u:Fake-Database-Passw0rd-1234@db'],
    );

    $redacted = (new RedactSecretsProcessor)($record);
    $rendered = $redacted->message.' '.json_encode($redacted->context);

    expect($rendered)
        ->not->toContain('sccit-render-relay-user')
        ->not->toContain('Fake-SMTP-Passw0rd-For-Tests')
        ->not->toContain('Fake-Database-Passw0rd-1234')
        ->toContain('[redacted]')
        // The useful part of the failure survives: what failed, and the SMTP code.
        ->toContain('Failed to authenticate on SMTP server')
        ->toContain('535');
});

it('leaves an exception without secrets as a structured exception', function () {
    $plain = new RuntimeException('Connection timed out');
    $record = new LogRecord(new DateTimeImmutable, 'test', Level::Error, 'x', ['exception' => $plain]);

    expect((new RedactSecretsProcessor)($record)->context['exception'])->toBe($plain);
});

it('installs the processor on the configured channels, so real log calls are redacted', function () {
    config(['logging.channels.redaction_probe' => [
        'driver' => 'monolog',
        'handler' => TestHandler::class,
        'tap' => [RedactSecrets::class],
    ]]);

    $logger = Log::channel('redaction_probe');
    $logger->error('SMTP failure', ['exception' => smtpAuthFailure()]);

    /** @var TestHandler $handler */
    $handler = $logger->getLogger()->getHandlers()[0];
    $written = json_encode(array_map(fn (LogRecord $r) => [$r->message, $r->context], $handler->getRecords()));

    expect($written)->not->toContain('sccit-render-relay-user')->toContain('[redacted]');
});

it('lists the tap on every channel production and development write to', function () {
    foreach (['single', 'daily', 'stderr', 'errorlog'] as $channel) {
        expect(config("logging.channels.$channel.tap"))->toContain(RedactSecrets::class);
    }
});

it('stores a failed mail job without the relay credentials', function () {
    $failer = app('queue.failer');
    expect($failer)->toBeInstanceOf(RedactingFailedJobProvider::class);

    $failer->log('database', 'notifications', json_encode(['uuid' => (string) Str::uuid(), 'displayName' => 'SendQueuedNotifications']), smtpAuthFailure());

    $stored = (string) DB::table('failed_jobs')->latest('id')->value('exception');

    expect($stored)
        ->not->toContain('sccit-render-relay-user')
        ->toContain('[redacted]')
        ->toContain('Failed to authenticate on SMTP server');
});

it('stores an ordinary failure unchanged', function () {
    app('queue.failer')->log('database', 'default', json_encode(['uuid' => (string) Str::uuid()]), new RuntimeException('Disk full'));

    expect((string) DB::table('failed_jobs')->latest('id')->value('exception'))->toContain('RuntimeException: Disk full');
});

it('delegates every other failed-job operation to the configured store', function () {
    $inner = Mockery::mock(FailedJobProviderInterface::class);
    $inner->shouldReceive('all')->once()->andReturn([]);
    $inner->shouldReceive('find')->with(7)->once()->andReturn(null);
    $inner->shouldReceive('forget')->with(7)->once()->andReturn(true);
    $inner->shouldReceive('ids')->with('mail')->once()->andReturn([7]);
    $inner->shouldReceive('flush')->with(24)->once();

    $provider = new RedactingFailedJobProvider($inner);

    expect($provider->all())->toBe([])
        ->and($provider->find(7))->toBeNull()
        ->and($provider->forget(7))->toBeTrue()
        ->and($provider->ids('mail'))->toBe([7]);
    $provider->flush(24);
});
