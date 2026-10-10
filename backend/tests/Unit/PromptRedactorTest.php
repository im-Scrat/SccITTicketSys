<?php

declare(strict_types=1);

use App\Domains\KnowledgeBase\Services\PromptRedactor;

/**
 * The redactor is the last line of defence before text leaves for the AI
 * provider (SRS FR-AI-030), so it is tested against the shapes people actually
 * type — and, as importantly, against the technical strings it must leave alone.
 */
it('removes email addresses', function (string $input) {
    expect((new PromptRedactor)->redact($input))->toContain('[email]')->not->toContain('@');
})->with([
    'plain' => 'Write to maria.santos@school.edu.ph about it',
    'end of sentence' => 'Contact help@school.test.',
    'uppercase' => 'CONTACT ME AT JOHN@EXAMPLE.COM NOW',
    'plus tag' => 'reach a+b@mail.example.org today',
]);

it('removes phone numbers in common shapes', function (string $input) {
    expect((new PromptRedactor)->redact($input))->toContain('[phone]')->not->toMatch('/\d{3}[\s.\-]\d{3}/');
})->with([
    'local with spaces' => 'Call 0917 123 4567 please',
    'local with dashes' => 'Call 0917-123-4567',
    'international' => 'Call +63 917 123 4567 today',
    'sentence end' => 'Her number is 0917 123 4567.',
    'landline' => 'Call (02) 8123 4567',
    'bare mobile' => 'Text 09171234567 now',
]);

it('leaves technical identifiers alone', function (string $input) {
    $out = (new PromptRedactor)->redact($input);

    expect($out)->toBe($input);
})->with([
    'ticket reference' => 'Ticket TKT-2026-000123 is still open',
    'asset tag' => 'Asset tag LAB2-PC-014 has no display',
    'ip address' => 'The machine at 192.168.10.45 is unreachable',
    'serial' => 'Serial number SN1234567890 is engraved underneath',
    'version' => 'BIOS version 2.14.1 is installed',
    'short number' => 'Room 204 has 30 seats',
]);

it('removes supplied names, whole and in parts, longest first', function () {
    $out = (new PromptRedactor)->redact('Maria Santos reported it. Later Maria called back; Santos was upset.', ['Maria Santos']);

    expect($out)->not->toContain('Maria')->not->toContain('Santos')->toContain('[person]');
});

it('does not shred ordinary words when a short name part is supplied', function () {
    $out = (new PromptRedactor)->redact('Jo Li said the monitor is blank', ['Jo Li']);

    expect($out)->toContain('monitor')->toContain('blank');
});

it('is case-insensitive about names and leaves an empty name list untouched', function () {
    expect((new PromptRedactor)->redact('ask MARIA', ['maria']))->toBe('ask [person]');
    expect((new PromptRedactor)->redact('nothing to see', []))->toBe('nothing to see');
});

/* ---------------------------------------- credentials (pre-commit fix pass) */

it('removes credentials a user types or pastes', function (string $input, string $secret) {
    $out = (new PromptRedactor)->redact($input);

    expect($out)->not->toContain($secret)->toContain('[secret]');
})->with([
    'password label' => ['my password: Hunter2-Galaxy!', 'Hunter2-Galaxy!'],
    'password is' => ['my password is Hunter2-Galaxy!', 'Hunter2-Galaxy!'],
    'quoted pwd' => ['pwd="Hunter2 Galaxy"', 'Hunter2 Galaxy'],
    'api key label' => ['api key = sk_live_abc123', 'sk_live_abc123'],
    'bearer' => ['Authorization: Bearer abc.def.ghi-jkl_mno', 'abc.def.ghi-jkl_mno'],
    'sanctum token' => ['token 7|AbCdEfGhIjKlMnOpQrStUvWxYz0123456789abcd', 'AbCdEfGhIjKlMnOpQrStUvWxYz0123456789abcd'],
    'jwt' => ['jwt eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.c2lnbmF0dXJl', 'eyJhbGciOiJIUzI1NiJ9'],
    'laravel cookie' => ['cookie value eyJpdiI6IkFCQ0QiLCJ2YWx1ZSI6Inh5eiJ9', 'eyJpdiI6IkFCQ0Qi'],
    'google key' => ['key AIzaSyA1234567890abcdefghijklmnopqrstu', 'AIzaSyA1234567890'],
    'app key' => ['APP_KEY base64:c2VjcmV0c2VjcmV0c2VjcmV0c2VjcmV0MTIzNA==', 'c2VjcmV0c2VjcmV0'],
    'bcrypt hash' => ['hash $2y$12$abcdefghijklmnopqrstuuJxQZ0n1Hq8m3b1p8vH0e2tM6YfGQyW2', '$2y$12$abcdefghijklmnop'],
    'argon hash' => ['$argon2id$v=19$m=65536,t=4,p=1$c29tZXNhbHQ$aGFzaA', '$argon2id$'],
    'long hex' => ['sha 9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08', '9f86d081884c7d659a2f'],
]);

it('removes this deployment\'s configured secrets by value, whatever their shape', function () {
    config(['ai.providers.gemini.key' => 'plain-words-key-value', 'database.connections.pgsql.password' => 'correct horse battery']);

    $out = (new PromptRedactor)->redact('tried plain-words-key-value then correct horse battery');

    expect($out)->not->toContain('plain-words-key-value')->not->toContain('correct horse battery');
});

it('leaves fault descriptions that merely mention credentials alone', function (string $input) {
    expect((new PromptRedactor)->redact($input))->toBe($input);
})->with([
    'pin is bent' => 'One CPU pin is bent on the socket',
    'token expired' => 'The login token is expired again',
    'forgot password' => 'I forgot my password for the lab PC',
    'uuid' => 'Ticket 436785c7-927b-4fc1-afcc-2d3d008f638c',
    'url path' => 'See https://support.example.com/kb/how-to-reset-the-projector-lamp-timer-2024',
    'mac address' => 'MAC 00:1A:2B:3C:4D:5E on port 12',
]);

it('leaves role and generic words alone even when an account is named after them', function () {
    $out = (new PromptRedactor)->redact('Open tickets per technician. All tickets in the system. System Administrator approved it.', ['System Administrator', 'technician ko']);

    expect($out)->toContain('per technician')->toContain('in the system')->toContain('[person] approved')->not->toContain('System Administrator');
});

it('does not read a page label as a credential', function () {
    expect((new PromptRedactor)->redact('Account settings (name, password change): /app/account'))->toContain('/app/account');
});
