<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

/**
 * The SMTP2GO settings are plain configuration: these prove each one reaches
 * the Symfony transport Laravel builds, so production can rely on env alone.
 * No network — building a transport does not connect.
 */
it('builds an SMTP transport that requires TLS, with the configured timeout and EHLO domain', function () {
    config(['mail.mailers.relay_probe' => [
        'transport' => 'smtp',
        'scheme' => 'smtp',
        'host' => 'mail.smtp2go.com',
        'port' => 2525,
        'username' => 'relay-user',
        'password' => 'relay-pass',
        'timeout' => 10.0,
        'local_domain' => 'sccit-pilot.onrender.com',
        'require_tls' => true,
    ]]);

    $transport = Mail::mailer('relay_probe')->getSymfonyTransport();

    expect($transport)->toBeInstanceOf(EsmtpTransport::class);
    /** @var EsmtpTransport $transport */
    expect($transport->isTlsRequired())->toBeTrue()
        ->and($transport->getLocalDomain())->toBe('sccit-pilot.onrender.com')
        ->and($transport->getStream()->getTimeout())->toBe(10.0)
        ->and($transport->getUsername())->toBe('relay-user');
});

it('keeps Mailpit working by default: TLS not required and no forced timeout', function () {
    expect(config('mail.mailers.smtp.require_tls'))->toBeFalse()
        ->and(config('mail.mailers.smtp.timeout'))->toBeNull()
        ->and(config('mail.test_redirect_to'))->toBeNull();
});

it('reads every SMTP2GO setting from the environment, never from code', function () {
    $source = (string) file_get_contents(config_path('mail.php'));

    foreach (['MAIL_TIMEOUT', 'MAIL_REQUIRE_TLS', 'MAIL_TEST_REDIRECT_TO', 'MAIL_USERNAME', 'MAIL_PASSWORD', 'MAIL_EHLO_DOMAIN'] as $variable) {
        expect($source)->toContain("env('{$variable}'");
    }

    expect($source)->not->toContain('smtp2go');
});
