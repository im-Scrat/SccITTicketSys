<?php

declare(strict_types=1);

use App\Domains\KnowledgeBase\Services\AiSettings;
use App\Domains\KnowledgeBase\Services\GeminiCredential;
use Illuminate\Support\Facades\Log;

/**
 * WP-O — the Gemini key comes from a Docker secret FILE, never from config
 * (SDD DD-74). These tests prove the mechanism with a throwaway file and a
 * throwaway string; nothing here is, or resembles, a real credential, and
 * nothing here contacts Google.
 */
const SECRET_VALUE = 'test-secret-value-not-a-real-credential';

beforeEach(function () {
    $this->secretFile = tempnam(sys_get_temp_dir(), 'gemini-secret-');
    config([
        'ai.sccit.gemini_key_file' => $this->secretFile,
        'ai.sccit.gemini_inline_key' => null,
        'ai.providers.gemini.key' => null,
    ]);
    GeminiCredential::forgetWarning();
});

afterEach(function () {
    @unlink($this->secretFile);
    putenv('GEMINI_API_KEY');
});

it('reads the key from the mounted secret file and applies it to the provider config', function () {
    file_put_contents($this->secretFile, SECRET_VALUE."\n");

    GeminiCredential::apply();

    expect(config('ai.providers.gemini.key'))->toBe(SECRET_VALUE)
        ->and(app(AiSettings::class)->hasProviderKey('gemini'))->toBeTrue();
});

it('trims the trailing newline an editor or echo leaves behind', function () {
    file_put_contents($this->secretFile, '  '.SECRET_VALUE." \r\n");

    expect(GeminiCredential::resolve())->toBe(SECRET_VALUE);
});

it('treats a missing, empty or unreadable file as no key', function () {
    expect(GeminiCredential::resolve())->toBeNull();

    file_put_contents($this->secretFile, "   \n");
    expect(GeminiCredential::resolve())->toBeNull();

    config(['ai.sccit.gemini_key_file' => '/nonexistent/run/secrets/gemini_api_key']);
    expect(GeminiCredential::resolve())->toBeNull();

    GeminiCredential::apply();
    expect(app(AiSettings::class)->hasProviderKey('gemini'))->toBeFalse();
});

it('falls back to the inline key outside production only', function () {
    config(['ai.sccit.gemini_inline_key' => 'inline-dev-key']);

    expect(GeminiCredential::resolve())->toBe('inline-dev-key');

    // The file still wins when both exist.
    file_put_contents($this->secretFile, SECRET_VALUE);
    expect(GeminiCredential::resolve())->toBe(SECRET_VALUE);
});

it('ignores an inline key in production and warns once, without printing it', function () {
    $this->app['env'] = 'production';
    putenv('GEMINI_API_KEY=should-never-be-used');
    config(['ai.sccit.gemini_inline_key' => 'should-never-be-used']);

    Log::spy();

    expect(GeminiCredential::resolve())->toBeNull()
        ->and(GeminiCredential::resolve())->toBeNull();

    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message): bool => str_contains($message, 'GEMINI_API_KEY') && ! str_contains($message, 'should-never-be-used'),
    );
});

it('uses the secret file in production', function () {
    $this->app['env'] = 'production';
    file_put_contents($this->secretFile, SECRET_VALUE);

    expect(GeminiCredential::resolve())->toBe(SECRET_VALUE);
});

it('does not overwrite an installed key with null when nothing resolves', function () {
    config(['ai.providers.gemini.key' => 'already-installed']);

    GeminiCredential::apply();

    expect(config('ai.providers.gemini.key'))->toBe('already-installed');
});

it('reports the state of the secret without revealing it', function () {
    file_put_contents($this->secretFile, SECRET_VALUE);

    $status = GeminiCredential::status();

    expect($status['source'])->toBe('file')
        ->and($status['file_exists'])->toBeTrue()
        ->and($status['file_readable'])->toBeTrue()
        ->and($status['file_empty'])->toBeFalse()
        ->and(json_encode($status))->not->toContain(SECRET_VALUE);

    file_put_contents($this->secretFile, '');
    expect(GeminiCredential::status())->toMatchArray(['source' => 'none', 'file_empty' => true]);
});

it('keeps the key out of the shipped configuration', function () {
    // The committed config holds the secret's PATH, and a null key — so
    // `config:cache` (which serialises whatever config/ai.php yields) has
    // nothing to leak.
    $config = require base_path('config/ai.php');

    expect($config['providers']['gemini']['key'])->toBeNull()
        ->and($config['sccit']['gemini_key_file'])->toBeString();
});
