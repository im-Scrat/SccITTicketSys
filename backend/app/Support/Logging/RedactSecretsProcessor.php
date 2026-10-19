<?php

declare(strict_types=1);

namespace App\Support\Logging;

use App\Support\Security\ConfiguredSecrets;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Stringable;
use Throwable;

/**
 * Removes this deployment's credentials from every log record before a handler
 * writes it (NFR-SEC-005: secrets never in logs).
 *
 * The leak this closes is concrete, not hypothetical: a failed SMTP login
 * throws `Failed to authenticate on SMTP server with username "…"`, and
 * Laravel logs the exception — message *and* stack trace — through `report()`.
 * With `zend.exception_ignore_args` off, trace frames also print the first
 * characters of string arguments, which for an AUTH LOGIN frame is the
 * base64-encoded password. Production now ignores exception arguments
 * (docker/php/php.prod.ini); this processor is the second layer, and the one
 * that also covers credentials quoted in a message.
 *
 * An exception in the context is only rewritten when its rendered text
 * actually contains a secret — it is then replaced by that redacted text, so
 * the formatter prints the same content minus the credential. Exceptions that
 * carry nothing sensitive keep their normal structured formatting.
 */
final class RedactSecretsProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $secrets = ConfiguredSecrets::values();

        if ($secrets === []) {
            return $record;
        }

        return $record->with(
            message: $this->redact($record->message, $secrets),
            context: $this->redactArray($record->context, $secrets),
            extra: $this->redactArray($record->extra, $secrets),
        );
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @param  list<string>  $secrets
     * @return array<array-key, mixed>
     */
    private function redactArray(array $values, array $secrets): array
    {
        foreach ($values as $key => $value) {
            $values[$key] = $this->redactValue($value, $secrets);
        }

        return $values;
    }

    /**
     * @param  list<string>  $secrets
     */
    private function redactValue(mixed $value, array $secrets): mixed
    {
        if (is_string($value)) {
            return $this->redact($value, $secrets);
        }

        if (is_array($value)) {
            return $this->redactArray($value, $secrets);
        }

        if ($value instanceof Throwable) {
            $text = $this->describe($value);
            $redacted = $this->redact($text, $secrets);

            return $redacted === $text ? $value : $redacted;
        }

        if ($value instanceof Stringable) {
            $text = (string) $value;
            $redacted = $this->redact($text, $secrets);

            return $redacted === $text ? $value : $redacted;
        }

        return $value;
    }

    /** The exception chain as a formatter would print it: class, message, trace. */
    private function describe(Throwable $e): string
    {
        $parts = [];

        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            $parts[] = sprintf('[object] (%s(code: %s): %s at %s:%d)', $current::class, (string) $current->getCode(), $current->getMessage(), $current->getFile(), $current->getLine());
            $parts[] = '[stacktrace]';
            $parts[] = $current->getTraceAsString();
        }

        return implode("\n", $parts);
    }

    /**
     * @param  list<string>  $secrets
     */
    private function redact(string $text, array $secrets): string
    {
        foreach ($secrets as $secret) {
            $text = str_replace($secret, '[redacted]', $text);
        }

        return $text;
    }
}
