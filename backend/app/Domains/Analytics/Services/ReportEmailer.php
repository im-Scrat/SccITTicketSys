<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Services;

use App\Domains\Analytics\Http\Requests\StoreReportEmailRequest;
use App\Domains\Analytics\Jobs\ReportExportJob;
use App\Domains\Analytics\Notifications\ReportEmailNotification;
use App\Domains\Identity\Services\AuditLogger;
use App\Enums\ActivityAction;
use App\Enums\ReportContentProfile;
use App\Enums\ReportEmailDeliveryStatus;
use App\Enums\ReportExportFormat;
use App\Enums\ReportExportPurpose;
use App\Enums\ReportType;
use App\Models\ReportEmailDelivery;
use App\Models\ReportExport;
use App\Models\User;
use App\Support\SchoolClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Emailing a report to any address, with safeguards (Phase 2.10, owner
 * decision D1, SDD DD-92).
 *
 * The safeguards, each enforced on the server:
 *  - **What leaves.** Every emailed attachment uses the identifier-only
 *    profile — ticket numbers, categories, statuses, room codes, dates; never a
 *    title, description or note, which are free text a reporter typed. The
 *    database refuses an emailed row with any other profile.
 *  - **Who receives.** 1–10 valid, distinct addresses; every address outside
 *    the school's domains must be confirmed one by one, and the server checks
 *    the confirmation against its own list ({@see StoreReportEmailRequest}).
 *  - **How many.** At most `reports.email.daily_cap` recipients per
 *    administrator per school day, counted under a per-administrator advisory
 *    lock so parallel requests cannot both pass the check; plus a per-hour
 *    rate limit on the route.
 *  - **The trail.** The request is audited in the same transaction as the
 *    delivery rows, with the recipients and the confirmed outside addresses.
 *
 * Status is honest: a delivery is `sent` when the mail server accepted it,
 * never "delivered" — the application cannot know that.
 */
final class ReportEmailer
{
    public function __construct(
        private readonly ReportExporter $exporter,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * The school's own mail domains: the configured list plus the domain the
     * application sends from.
     *
     * @return list<string>
     */
    public function internalDomains(): array
    {
        $domains = (array) config('reports.email.internal_domains', []);
        $from = (string) config('mail.from.address', '');

        if (str_contains($from, '@')) {
            $domains[] = strtolower(substr($from, strrpos($from, '@') + 1));
        }

        return array_values(array_unique(array_filter(array_map('strval', $domains))));
    }

    /**
     * @param  array<int, mixed>  $recipients
     * @return list<string>
     */
    public function externalOf(array $recipients): array
    {
        $internal = $this->internalDomains();
        $external = [];

        foreach ($recipients as $address) {
            if (! is_string($address) || ! str_contains($address, '@')) {
                continue;
            }
            $domain = strtolower(substr($address, strrpos($address, '@') + 1));
            if (! in_array($domain, $internal, true)) {
                $external[] = strtolower($address);
            }
        }

        return array_values(array_unique($external));
    }

    /**
     * What the dialog shows before anything is sent.
     *
     * @param  list<string>  $recipients
     * @return array<string, mixed>
     */
    public function preview(ReportType $type, ReportExportFormat $format, array $recipients, User $actor): array
    {
        $sentToday = $this->sentToday($actor);
        $cap = (int) config('reports.email.daily_cap', 50);

        return [
            'recipients' => array_map(fn (string $r): array => [
                'address' => $r,
                'external' => in_array($r, $this->externalOf([$r]), true),
            ], $recipients),
            'external' => $this->externalOf($recipients),
            'internal_domains' => $this->internalDomains(),
            'delivers_externally' => (bool) config('reports.email.delivers_externally', false),
            'daily_cap' => $cap,
            'sent_today' => $sentToday,
            'remaining_today' => max($cap - $sentToday, 0),
            'contents' => [
                'report' => $type->label(),
                'format' => $format->label(),
                'includes' => [
                    'The summary, headline figures, charts and their tables',
                    'Ticket records as identifiers only (when the report includes records)',
                    'How the figures are counted, and the report\'s limitations',
                ],
                'excludes' => [
                    'No ticket titles, descriptions, comments or notes',
                    'No names of reporters or technicians',
                    'No link back to this system for addresses outside the school',
                ],
            ],
        ];
    }

    /**
     * Record the email and queue its document; the job sends once the file exists.
     *
     * @param  list<string>  $recipients
     * @param  array<string, mixed>  $parameters
     * @param  array{paper: string, orientation: string, include_records: bool}  $options
     *
     * @throws ValidationException when the daily cap would be exceeded
     */
    public function request(
        ReportType $type,
        ReportExportFormat $format,
        array $parameters,
        CarbonImmutable $asOf,
        array $options,
        array $recipients,
        User $actor,
        Request $request,
    ): ReportExport {
        $external = $this->externalOf($recipients);

        $export = DB::transaction(function () use ($type, $format, $parameters, $asOf, $options, $recipients, $external, $actor, $request): ReportExport {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['report-email:'.$actor->getKey()]);

            $cap = (int) config('reports.email.daily_cap', 50);
            $sentToday = $this->sentToday($actor);

            if ($sentToday + count($recipients) > $cap) {
                throw ValidationException::withMessages([
                    'recipients' => sprintf(
                        'You can email reports to %d recipients a day; %d remain today.',
                        $cap,
                        max($cap - $sentToday, 0),
                    ),
                ]);
            }

            $export = $this->exporter->request(
                $type, $format, $parameters, $asOf, $options,
                ReportContentProfile::Identifiers, ReportExportPurpose::Email,
                $actor, $request, dispatch: false,
            );

            foreach ($recipients as $address) {
                ReportEmailDelivery::query()->create([
                    'report_export_id' => $export->id,
                    'requested_by' => $actor->getKey(),
                    'recipient' => $address,
                    'is_external' => in_array($address, $external, true),
                    'status' => ReportEmailDeliveryStatus::Queued,
                ]);
            }

            $this->audit->activity(
                ActivityAction::ReportEmailRequested,
                $actor,
                $export,
                [
                    'report_type' => $type->value,
                    'format' => $format->value,
                    'recipients' => $recipients,
                    'confirmed_external' => $external,
                ],
                $request,
                'reports',
            );

            return $export;
        });

        ReportExportJob::dispatch($export->id);

        return $export;
    }

    /** Hand each queued delivery to the mail queue, once the file exists. */
    public function deliver(ReportExport $export): void
    {
        $export->loadMissing('user');

        foreach ($export->deliveries()->where('status', ReportEmailDeliveryStatus::Queued->value)->get() as $delivery) {
            Notification::route('mail', $delivery->recipient)
                ->notify(new ReportEmailNotification($export, $delivery));
        }
    }

    /** Recipients this administrator has emailed reports to since school midnight. */
    public function sentToday(User $actor): int
    {
        return ReportEmailDelivery::query()
            ->where('requested_by', $actor->getKey())
            ->where('created_at', '>=', SchoolClock::today()->utc())
            ->count();
    }
}
