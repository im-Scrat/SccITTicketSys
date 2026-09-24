<?php

declare(strict_types=1);

/**
 * Security response headers, and the one piece of the policy that can rot
 * silently (SDD DD-46).
 *
 * Two different things are covered here:
 *
 *  1. **What Laravel sends.** Asserted against real responses, because a
 *     middleware that is registered but not reached is worth nothing.
 *  2. **What nginx is configured to send.** Laravel does not serve the SPA —
 *     nginx does — so the page-level CSP cannot be asserted from a Laravel
 *     response. What *can* be asserted is that the policy files still say what
 *     the application actually needs, and that is exactly where the silent
 *     failure lives: the production CSP pins index.html's inline theme script
 *     by SHA-256, and editing that script without updating the hash breaks
 *     theming in production only, with no test and no error to catch it.
 */
function repoPath(string $relative): string
{
    // Only `backend/` is mounted at the application root, so the repository's
    // nginx and frontend files are surfaced separately at /repo (read-only,
    // declared in compose.yaml for exactly this suite).
    foreach (['/repo/'.$relative, base_path('..').'/'.$relative] as $candidate) {
        if (is_readable($candidate)) {
            return $candidate;
        }
    }

    return '/repo/'.$relative;
}

/** The CSP source-hash of index.html's single inline <script>. */
function inlineScriptHash(): string
{
    $html = (string) file_get_contents(repoPath('frontend/index.html'));

    expect(preg_match('/<script>(.*?)<\/script>/s', $html, $m))->toBe(1)
        ->and($m[1])->toContain('sccit-theme');

    return 'sha256-'.base64_encode(hash('sha256', $m[1], true));
}

it('pins the production CSP to the current inline theme script', function () {
    $conf = (string) file_get_contents(repoPath('docker/nginx/security-headers.prod.conf'));

    // If this fails, frontend/index.html's inline script changed and the hash in
    // the nginx snippet was not regenerated. Production would silently stop
    // executing it: the theme would flash and then resolve late.
    expect($conf)->toContain(inlineScriptHash());
})->group('security');

it('keeps blob: in img-src so attachment previews keep working', function () {
    // The SPA fetches attachments over authenticated XHR and renders them from
    // URL.createObjectURL(). Dropping blob: while "tightening" the policy breaks
    // every image preview in Tickets and Assets — a regression that looks like a
    // UI bug and is actually a header.
    foreach (['dev', 'prod'] as $env) {
        $conf = (string) file_get_contents(repoPath("docker/nginx/security-headers.{$env}.conf"));

        expect($conf)->toContain('blob:')
            ->and($conf)->toContain('img-src')
            ->and($conf)->toContain('nosniff')
            ->and($conf)->toContain("frame-ancestors 'none'")
            ->and($conf)->toContain("object-src 'none'");
    }
})->group('security');

it('never allows unsafe-inline script in the production policy', function () {
    $prod = (string) file_get_contents(repoPath('docker/nginx/security-headers.prod.conf'));

    // Read the directive out of the actual add_header line, not the file: the
    // comment block above it discusses 'unsafe-inline' by name, and matching
    // the file as a whole would test the prose instead of the policy.
    expect(preg_match('/add_header Content-Security-Policy "([^"]+)"/', $prod, $header))->toBe(1);
    expect(preg_match('/script-src([^;]*)/', $header[1], $m))->toBe(1);

    expect($m[1])->not->toContain('unsafe-inline')
        ->and($m[1])->not->toContain('unsafe-eval')
        ->and($m[1])->toContain('sha256-');
})->group('security');

it('sends baseline security headers on API responses', function () {
    $response = $this->getJson('/api/health');

    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin')
        ->and($response->headers->get('Content-Security-Policy'))->toContain("default-src 'none'");
})->group('security');

it('sends security headers on authenticated JSON too', function () {
    seedRbac();
    $admin = userWithRole('administrator');

    $response = $this->actingAs($admin)->getJson('/api/user');

    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Security-Policy'))->toContain('sandbox');
})->group('security');
