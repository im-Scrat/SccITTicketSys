<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Services;

use App\Domains\Analytics\DTOs\ReportDocument;
use App\Domains\Analytics\DTOs\ReportFilters;
use App\Domains\Analytics\DTOs\ReportTable;
use App\Domains\Analytics\Jobs\ReportExportJob;
use App\Domains\Identity\Services\AuditLogger;
use App\Enums\ActivityAction;
use App\Enums\ReportContentProfile;
use App\Enums\ReportEmailDeliveryStatus;
use App\Enums\ReportExportFormat;
use App\Enums\ReportExportPurpose;
use App\Enums\ReportExportStatus;
use App\Enums\ReportType;
use App\Models\ReportExport;
use App\Models\User;
use App\Support\SchoolClock;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Queued report documents (Phase 2.10, SDD DD-91).
 *
 * **Request.** A document is recorded with the validated filters and the
 * instant the screen reported up to, then rendered by {@see ReportExportJob} on
 * the bulk worker — never inside the HTTP request, whose 120 s limit a large
 * workbook could exceed. An identical download asked for again within the same
 * minute returns the existing row instead of a second job.
 *
 * **Render.** The job *claims* the row (`pending` → `processing` in one
 * conditional update, so a re-delivered job cannot render twice), re-checks
 * that the administrator may still export, rebuilds the exact filters, renders,
 * writes the file to the private disk and only then marks it completed: a
 * failed write (`Storage::put` returning false) is an error, and size and
 * sha256 are read back from the stored file. `ReportExported` is audited after
 * success, so the log never claims a file that does not exist.
 */
final class ReportExporter
{
    /** A row stuck in `processing` this long belonged to a killed worker. */
    private const STALE_PROCESSING_SECONDS = 300;

    public function __construct(
        private readonly ReportDocumentBuilder $builder,
        private readonly ReportXlsxRenderer $xlsx,
        private readonly ReportCsvRenderer $csv,
        private readonly ReportDocxRenderer $docx,
        private readonly ReportPdfRenderer $pdf,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Record a document and queue it.
     *
     * @param  array<string, mixed>  $parameters  validated report parameters
     * @param  array{paper: string, orientation: string, include_records: bool, include_narrative?: bool}  $options
     */
    public function request(
        ReportType $type,
        ReportExportFormat $format,
        array $parameters,
        CarbonImmutable $asOf,
        array $options,
        ReportContentProfile $profile,
        ReportExportPurpose $purpose,
        User $actor,
        Request $request,
        bool $dispatch = true,
    ): ReportExport {
        ksort($parameters);
        ksort($options);
        $key = hash('sha256', json_encode([
            $actor->getKey(), $type->value, $format->value, $profile->value, $purpose->value,
            $parameters, $options, $asOf->utc()->format('Y-m-d\TH:i'),
        ], JSON_THROW_ON_ERROR));

        if ($purpose === ReportExportPurpose::Download) {
            $existing = ReportExport::query()
                ->where('dedupe_key', $key)
                ->where('user_id', $actor->getKey())
                ->whereIn('status', [ReportExportStatus::Pending->value, ReportExportStatus::Processing->value, ReportExportStatus::Completed->value])
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->latest('id')
                ->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        $export = ReportExport::query()->create([
            'user_id' => $actor->getKey(),
            'report_type' => $type,
            'format' => $format,
            'status' => ReportExportStatus::Pending,
            'profile' => $profile,
            'purpose' => $purpose,
            'filters' => $parameters,
            'options' => $options,
            'as_of' => $asOf->utc(),
            'dedupe_key' => $key,
            'request_ip' => $request->ip(),
            'request_user_agent' => mb_substr((string) $request->userAgent(), 0, 512),
        ]);

        if ($dispatch) {
            ReportExportJob::dispatch($export->id)->afterCommit();
        }

        return $export;
    }

    /**
     * Render a claimed document. Returns false when another worker holds it
     * (or it is no longer waiting); a failure marks the row failed.
     */
    public function render(int $exportId): bool
    {
        $claimed = ReportExport::query()
            ->whereKey($exportId)
            ->where(fn ($q) => $q
                ->where('status', ReportExportStatus::Pending->value)
                ->orWhere(fn ($q) => $q
                    ->where('status', ReportExportStatus::Processing->value)
                    ->where('started_at', '<', now()->subSeconds(self::STALE_PROCESSING_SECONDS))))
            ->update([
                'status' => ReportExportStatus::Processing->value,
                'started_at' => now(),
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => now(),
            ]);

        if ($claimed === 0) {
            return false;
        }

        /** @var ReportExport $export */
        $export = ReportExport::query()->with('user')->findOrFail($exportId);

        try {
            $actor = $export->user ?? throw new AuthorizationException('The requesting account no longer exists.');
            $document = $this->document($export, $actor);
            [$bytes, $rendered] = $this->bytes($export, $document);

            $filename = $this->filename($export, $rendered);
            $path = sprintf('report-exports/%s/%s', $export->uuid, $filename);
            $disk = Storage::disk($export->disk);

            if ($disk->put($path, $bytes) !== true) {
                throw new RuntimeException('The report file could not be written.');
            }

            $size = $disk->size($path);
            $stream = $disk->readStream($path);
            $context = hash_init('sha256');
            if (is_resource($stream)) {
                hash_update_stream($context, $stream);
                fclose($stream);
            }

            $records = $rendered->recordsTable();
            $truncated = array_filter($rendered->tables(), static fn (ReportTable $t): bool => $t->truncated) !== [];

            $export->forceFill([
                'narrative_id' => $document->narrativeId,
                'status' => ReportExportStatus::Completed,
                'path' => $path,
                'filename' => $filename,
                'size_bytes' => $size,
                'sha256' => hash_final($context),
                'row_count' => $records?->shownRows() ?? $rendered->rowCount(),
                'truncated' => $truncated,
                'error_code' => null,
                'completed_at' => now(),
                'expires_at' => now()->addHours((int) config('reports.exports.retention_hours', 72)),
            ])->save();

            $this->audit->activity(
                ActivityAction::ReportExported,
                $actor,
                $export,
                [
                    'report_type' => $export->report_type->value,
                    'format' => $export->format->value,
                    'purpose' => $export->purpose->value,
                    'profile' => $export->profile->value,
                    'rows' => $export->row_count,
                    'truncated' => $truncated,
                    'bytes' => $size,
                ],
                $this->requestFor($export),
                'reports',
            );

            return true;
        } catch (AuthorizationException $e) {
            $this->fail($export, 'not_authorized');

            return true;
        } catch (Throwable $e) {
            // Logged, not rethrown: a failed render is final for this row (a
            // retry would find it no longer pending), and the administrator
            // sees "Failed" with the option to ask again.
            $this->fail($export, 'render_failed');
            report($e);

            return true;
        }
    }

    /** Mark a document failed, and with it any email still waiting for it. */
    public function fail(ReportExport $export, string $code): void
    {
        $export->forceFill([
            'status' => ReportExportStatus::Failed,
            'error_code' => $code,
            'completed_at' => now(),
        ])->save();

        $export->deliveries()
            ->where('status', ReportEmailDeliveryStatus::Queued->value)
            ->update([
                'status' => ReportEmailDeliveryStatus::Failed->value,
                'failure_code' => 'export_failed',
                'failed_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * @throws AuthorizationException
     */
    public function document(ReportExport $export, User $actor): ReportDocument
    {
        $filters = ReportFilters::fromValidated(
            $export->filters,
            CarbonImmutable::instance($export->as_of)->setTimezone(SchoolClock::TIMEZONE),
        );

        return $this->builder->build(
            $export->report_type,
            $filters,
            $actor,
            new ReportBuildOptions(
                profile: $export->profile,
                includeRecords: (bool) ($export->options['include_records'] ?? false),
                recordLimit: (int) config('reports.exports.record_limit', 10000),
                includeNarrative: (bool) ($export->options['include_narrative'] ?? false),
            ),
            $export->filters,
        );
    }

    /**
     * @return array{0: string, 1: ReportDocument} the bytes and the document as rendered (after budgets)
     */
    private function bytes(ReportExport $export, ReportDocument $document): array
    {
        $paged = (int) config('reports.exports.paged_row_budget', 600);
        $options = ['paper' => (string) ($export->options['paper'] ?? 'a4'), 'orientation' => (string) ($export->options['orientation'] ?? 'portrait')];

        return match ($export->format) {
            ReportExportFormat::Pdf => [$this->pdf->render($budgeted = $document->withRowBudget($paged), $options), $budgeted],
            ReportExportFormat::Docx => [$this->docx->render($budgeted = $document->withRowBudget($paged), $options), $budgeted],
            ReportExportFormat::Xlsx => [$this->xlsx->render($document, (int) config('reports.exports.xlsx_cell_budget', 150000)), $document],
            ReportExportFormat::Csv => [$this->csv->render($document), $document],
        };
    }

    /** ASCII, school time, no user data: `ticket-overview_2026-10-01_2026-10-18_20261018-1741.pdf`. */
    private function filename(ReportExport $export, ReportDocument $document): string
    {
        $from = (string) ($export->filters['from'] ?? '');
        $stamp = SchoolClock::now()->format('Ymd-Hi');
        $period = preg_replace('/[^a-z0-9]+/', '-', strtolower((string) ($export->filters['period'] ?? 'month'))) ?: 'report';

        return sprintf(
            '%s_%s%s_%s.%s',
            $export->report_type->fileStem(),
            $period,
            $from !== '' ? '-'.$from : '',
            $stamp,
            $export->format->extension(),
        );
    }

    /** The original request's address and agent, for audit rows written by a job. */
    public function requestFor(ReportExport $export): Request
    {
        return Request::create('/', 'POST', server: array_filter([
            'REMOTE_ADDR' => $export->request_ip,
            'HTTP_USER_AGENT' => $export->request_user_agent,
        ]));
    }
}
