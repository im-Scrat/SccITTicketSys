<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

/**
 * `php artisan mail:check`: configuration without credentials, a connection
 * test that sends nothing, and an explicit single test message. No test here
 * reaches a real relay — failures are simulated by transport doubles or a
 * closed local port.
 */
beforeEach(function () {
    config([
        'mail.mailers.smtp.username' => 'relay-user-for-tests',
        'mail.mailers.smtp.password' => 'relay-password-for-tests',
    ]);
});

/** Use an ESMTP transport whose connection attempt fails as SMTP2GO would on bad credentials. */
function useRelayRefusingCredentials(): void
{
    Mail::extend('refusing_relay', fn () => new class('mail.smtp2go.com', 2525) extends EsmtpTransport
    {
        public function start(): void
        {
            throw new TransportException('Failed to authenticate on SMTP server with username "relay-user-for-tests" using the following authenticators: "LOGIN".', 535);
        }
    });
    config(['mail.mailers.refusing_relay' => ['transport' => 'refusing_relay'], 'mail.default' => 'refusing_relay']);
}

it('prints the configuration with credentials only as set or not set', function () {
    config(['mail.default' => 'smtp']);

    $this->artisan('mail:check --no-connect')
        ->expectsOutputToContain('username_set')
        ->doesntExpectOutputToContain('relay-user-for-tests')
        ->doesntExpectOutputToContain('relay-password-for-tests')
        ->assertSuccessful();
});

it('warns when a real relay is configured without required TLS or with a localhost link base', function () {
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'mail.smtp2go.com', 'mail.mailers.smtp.require_tls' => false, 'app.frontend_url' => 'http://localhost:8081']);

    $this->artisan('mail:check --no-connect')
        ->expectsOutputToContain('MAIL_REQUIRE_TLS')
        ->expectsOutputToContain('FRONTEND_URL points at localhost')
        ->assertSuccessful();
});

it('names an authentication failure without echoing the relay username', function () {
    useRelayRefusingCredentials();

    $this->artisan('mail:check')
        ->expectsOutputToContain('smtp_auth_failed (SMTP 535)')
        ->doesntExpectOutputToContain('relay-user-for-tests')
        ->assertFailed();
});

it('reports a relay it cannot reach as a connection failure', function () {
    config([
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => '127.0.0.1',
        'mail.mailers.smtp.port' => 9,
        'mail.mailers.smtp.timeout' => 2.0,
    ]);

    $this->artisan('mail:check')
        ->expectsOutputToContain('connection_failed')
        ->assertFailed();
});

it('sends nothing unless asked', function () {
    $this->artisan('mail:check')->assertFailed(); // the array transport is not SMTP: nothing to connect to

    expect(Mail::mailer()->getSymfonyTransport()->messages())->toHaveCount(0);
});

it('sends exactly one fixed test message to the address given', function () {
    $this->artisan('mail:check --no-connect --send=owner@example.org')
        ->expectsOutputToContain('accepted by the relay')
        ->expectsOutputToContain('Accepted is not delivered')
        ->assertSuccessful();

    $messages = Mail::mailer()->getSymfonyTransport()->messages();
    expect($messages)->toHaveCount(1);
    $message = $messages->first()->getOriginalMessage();
    expect($message->getTo()[0]->getAddress())->toBe('owner@example.org')
        ->and($message->getSubject())->toContain('mail delivery test');
});

it('refuses an invalid --send address before contacting anything', function () {
    $this->artisan('mail:check --send=not-an-address')->assertFailed();

    expect(Mail::mailer()->getSymfonyTransport()->messages())->toHaveCount(0);
});
