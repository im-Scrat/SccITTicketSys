<?php

declare(strict_types=1);

use App\Support\Attachments\AttachmentSecurity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * Settings the hosted (Render) deployment depends on, proved without a host:
 * the TLS-terminating proxy is trusted only when TRUST_PROXIES says so, new
 * files go to the configured disk, and the database queue cannot hand a
 * running job to a second worker.
 */
function bootWithEnv(array $env): void
{
    foreach ($env as $key => $value) {
        putenv($value === null ? $key : "{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

afterEach(function () {
    bootWithEnv(['TRUST_PROXIES' => null]);
    unset($_ENV['TRUST_PROXIES'], $_SERVER['TRUST_PROXIES']);
});

function probeSecure(object $test): array
{
    Route::get('/_test/scheme-probe', fn (Request $request) => ['secure' => $request->isSecure(), 'url' => url('/x')]);

    return $test->getJson('/_test/scheme-probe', ['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'pilot.example.org', 'X-Forwarded-Port' => '443'])->json();
}

it('ignores forwarded headers when no proxy is trusted (the Docker stack)', function () {
    expect(probeSecure($this)['secure'])->toBeFalse();
});

it('honours the proxy\'s https when TRUST_PROXIES is set (the Render edge)', function () {
    bootWithEnv(['TRUST_PROXIES' => '*']);
    $this->refreshApplication();

    $result = probeSecure($this);

    expect($result['secure'])->toBeTrue()
        ->and($result['url'])->toStartWith('https://');
});

it('writes new uploads to the configured disk, local by default', function () {
    expect(AttachmentSecurity::storageDisk())->toBe('local');

    config(['filesystems.attachments' => 's3']);
    expect(AttachmentSecurity::storageDisk())->toBe('s3');
});

it('never lets the database queue reclaim a job that may still be running', function () {
    $longest = 180; // GenerateEmbeddingJob::$timeout, the longest job

    expect(config('queue.connections.database.retry_after'))->toBeGreaterThan($longest)
        ->and(config('queue.connections.redis.retry_after'))->toBeGreaterThan($longest);
});
