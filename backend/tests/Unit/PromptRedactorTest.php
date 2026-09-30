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
