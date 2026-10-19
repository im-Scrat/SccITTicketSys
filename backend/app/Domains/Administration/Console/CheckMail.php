<?php

declare(strict_types=1);

namespace App\Domains\Administration\Console;

use App\Support\Mail\MailDiagnostics;
use App\Support\Mail\MailFailure;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * `php artisan mail:check` — is outgoing mail configured, and does the relay
 * accept us? The command-line twin of the administrator's mail panel, built on
 * the same {@see MailDiagnostics}.
 *
 * By default it prints the configuration (credentials only as set / not set)
 * and opens a connection to the relay — connect, EHLO, STARTTLS, authenticate
 * — then disconnects **without sending anything**. `--send=address` adds one
 * fixed test message to that address; it is the only way this command sends
 * mail, and it is never implied.
 *
 * Exit status: 0 when every requested step succeeded, 1 otherwise. Failures
 * print a {@see MailFailure} name and the SMTP code, never
 * the server's text (which can quote the relay username).
 */
class CheckMail extends Command
{
    protected $signature = 'mail:check
        {--send= : Also send one fixed test message to this address}
        {--no-connect : Only print the configuration; do not contact the relay}';

    protected $description = 'Show the mail configuration and test the relay connection (sends nothing unless --send is given)';

    public function handle(MailDiagnostics $diagnostics): int
    {
        $send = $this->option('send');

        if ($send !== null && Validator::make(['to' => $send], ['to' => ['required', 'email:rfc,strict']])->fails()) {
            $this->error('--send must be one valid email address.');

            return self::FAILURE;
        }

        $config = $diagnostics->configuration();

        $this->table(['Setting', 'Value'], collect($config)
            ->except('warnings')
            ->map(fn (mixed $value, string $key): array => [$key, match (true) {
                is_bool($value) => $value ? 'yes' : 'no',
                $value === null => '—',
                default => (string) $value,
            }])
            ->values()
            ->all());

        foreach ($config['warnings'] as $warning) {
            $this->warn("WARNING  {$warning}");
        }

        $ok = true;

        if (! $this->option('no-connect')) {
            $probe = $diagnostics->probe();
            $ok = $probe['ok'];
            $this->line($probe['ok']
                ? 'Connection test: OK — '.$probe['summary']
                : 'Connection test: FAILED — '.$probe['outcome']
                    .($probe['smtp_code'] !== null ? " (SMTP {$probe['smtp_code']})" : '')
                    .'. No message was sent.');
        }

        if ($send !== null) {
            $result = $diagnostics->sendTest((string) $send, 'mail:check');
            $ok = $ok && $result['ok'];
            if ($result['ok']) {
                $this->line('Test message accepted by the relay'.($result['message_id'] ? " — message id {$result['message_id']}" : '').'.');
                $this->line('Accepted is not delivered: check the inbox, and the provider\'s activity log for delivery, bounces and spam placement.');
            } else {
                $this->line('Test message FAILED — '.$result['outcome'].($result['smtp_code'] !== null ? " (SMTP {$result['smtp_code']})" : ''));
            }
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
