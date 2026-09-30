<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Services;

/**
 * Strips personal data from text **before** it leaves for the provider or is
 * written into the embedding store (SRS FR-AI-030, NFR-SEC-013).
 *
 * The rule is data minimisation, not perfect anonymisation: the model needs to
 * know *what is wrong with the machine*, never *who* reported it. So the
 * patterns below target what a person types into a fault description without
 * thinking — an email address, a phone number — plus the names the caller
 * already knows are in the surrounding record and passes in explicitly.
 *
 * Redaction is applied to the text *after* it is assembled, as a last line of
 * defence, rather than trusting every prompt builder to have left personal
 * fields out. A builder that forgets still cannot leak an address.
 *
 * It is deliberately conservative about what it removes: numbers that look like
 * asset tags, ticket references or IP addresses are technical data the model
 * genuinely needs, so the phone pattern requires the shape of a real phone
 * number rather than "any long digit run".
 */
class PromptRedactor
{
    public const EMAIL = '[email]';

    public const PHONE = '[phone]';

    public const NAME = '[person]';

    /**
     * @param  list<string>  $names  names known to appear in the record (reporter, technician, …)
     */
    public function redact(string $text, array $names = []): string
    {
        // Emails first: a name inside an address would otherwise be replaced
        // piecemeal and leave a half-redacted fragment behind.
        $text = (string) preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', self::EMAIL, $text);

        // International (+63 917 123 4567) and local (0917-123-4567,
        // (02) 8123 4567) shapes. Requires 9+ digits overall and a separator
        // pattern or a leading +, so a bare serial number survives. A number
        // glued to a preceding letter-dash (TKT-2026-000123) is a reference,
        // not a phone, so the match may not start after a hyphen.
        $text = (string) preg_replace_callback(
            '/(?<![\w.\-])(?:(?:\+\d{1,3}[\s.\-]?)?(?:\(\d{1,4}\)[\s.\-]?)?\d{2,4}[\s.\-]\d{3,4}[\s.\-]?\d{3,4}|\(\d{1,4}\)[\s.\-]?\d{3,4}[\s.\-]\d{3,4}|\+?\d{10,13})(?!\w|[.\-]\d)/',
            static fn (array $match): string => strlen((string) preg_replace('/\D/', '', $match[0])) >= 9
                ? self::PHONE
                : $match[0],
            $text,
        );

        foreach ($this->nameTokens($names) as $token) {
            $text = (string) preg_replace('/\b'.preg_quote($token, '/').'\b/iu', self::NAME, $text);
        }

        return $text;
    }

    /**
     * Whole names and their parts, longest first so "Maria Santos" is removed
     * as a unit before "Maria" alone is considered. Parts shorter than three
     * characters are skipped — replacing "Jo" or "Li" everywhere would shred
     * ordinary words.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    private function nameTokens(array $names): array
    {
        $tokens = [];

        foreach ($names as $name) {
            $name = trim($name);

            if ($name === '') {
                continue;
            }

            $tokens[] = $name;

            foreach (preg_split('/\s+/', $name) ?: [] as $part) {
                if (mb_strlen($part) >= 3) {
                    $tokens[] = $part;
                }
            }
        }

        $tokens = array_values(array_unique($tokens));
        usort($tokens, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return $tokens;
    }
}
