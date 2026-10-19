<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Support\Mail\MailDiagnostics;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * MAIL_TEST_REDIRECT_TO: in a sandbox, every message goes to one controlled
 * inbox; in production the setting is ignored — loudly — so real users keep
 * receiving their own mail and nobody else receives it.
 */
function applyRedirectPolicy(): void
{
    $method = new ReflectionMethod(AppServiceProvider::class, 'registerMailTestRedirect');
    $method->invoke(app()->getProvider(AppServiceProvider::class));
}

/** @return list<array{to: list<string>, cc: list<string>, bcc: list<string>}> */
function sentEnvelopes(): array
{
    return collect(Mail::mailer()->getSymfonyTransport()->messages())
        ->map(fn ($sent) => $sent->getOriginalMessage())
        ->map(fn ($m) => [
            'to' => array_map(fn ($a) => $a->getAddress(), $m->getTo()),
            'cc' => array_map(fn ($a) => $a->getAddress(), $m->getCc()),
            'bcc' => array_map(fn ($a) => $a->getAddress(), $m->getBcc()),
        ])
        ->values()
        ->all();
}

function sendToRealPeople(): void
{
    Mail::raw('Your password reset link', fn ($m) => $m->to('real.user@school.example')->cc('other@school.example')->bcc('hidden@school.example')->subject('Reset'));
}

it('reports no redirect when the variable is unset', function () {
    expect(MailDiagnostics::redirectState())->toBe(MailDiagnostics::REDIRECT_NONE);
});

it('sends every message to the sandbox inbox, dropping CC and BCC, outside production', function () {
    config(['mail.test_redirect_to' => 'sandbox-inbox@example.org']);
    applyRedirectPolicy();

    sendToRealPeople();

    expect(MailDiagnostics::redirectState())->toBe(MailDiagnostics::REDIRECT_ACTIVE)
        ->and(sentEnvelopes())->toBe([['to' => ['sandbox-inbox@example.org'], 'cc' => [], 'bcc' => []]]);
});

it('ignores the redirect in production, says so, and delivers to the real recipients', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['mail.test_redirect_to' => 'sandbox-inbox@example.org']);
    Log::spy();

    applyRedirectPolicy();
    sendToRealPeople();

    expect(MailDiagnostics::redirectState())->toBe(MailDiagnostics::REDIRECT_IGNORED)
        ->and(sentEnvelopes()[0]['to'])->toBe(['real.user@school.example']);
    Log::shouldHaveReceived('critical')->withArgs(fn (string $message) => str_contains($message, 'IGNORED'))->once();
});

it('shows the redirect only masked in diagnostics', function () {
    config(['mail.test_redirect_to' => 'sandbox-inbox@example.org']);

    $config = app(MailDiagnostics::class)->configuration();

    expect($config['redirect'])->toBe('active')
        ->and($config['redirect_to'])->toStartWith('s*')
        ->and($config['redirect_to'])->toEndWith('@example.org')
        ->and($config['redirect_to'])->not->toContain('sandbox-inbox');
});
