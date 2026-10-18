<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Jobs;

use App\Domains\Analytics\Services\ReportEmailer;
use App\Domains\Analytics\Services\ReportExporter;
use App\Enums\ReportExportPurpose;
use App\Enums\ReportExportStatus;
use App\Models\ReportExport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Renders one report document (Phase 2.10, SDD DD-91/DD-93).
 *
 * On the `bulk` queue, worked by `queue_bulk_prod` (`--timeout=170`), so a
 * large workbook never sits in front of notifications. `$timeout` stays below
 * the worker's and the connection's `retry_after` (240 s) stays above both.
 * Unique per document, and the exporter's claim makes a re-delivery harmless.
 * An emailed document hands over to {@see ReportEmailer} once the file exists.
 */
class ReportExportJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;

    public int $timeout = 150;

    public int $backoff = 15;

    public int $uniqueFor = 600;

    public function __construct(public readonly int $exportId)
    {
        $this->onQueue((string) config('reports.exports.queue', 'bulk'));
    }

    public function uniqueId(): string
    {
        return 'report-export:'.$this->exportId;
    }

    public function handle(ReportExporter $exporter, ReportEmailer $emailer): void
    {
        if (! $exporter->render($this->exportId)) {
            return;
        }

        $export = ReportExport::query()->find($this->exportId);

        if ($export !== null && $export->purpose === ReportExportPurpose::Email && $export->status === ReportExportStatus::Completed) {
            $emailer->deliver($export);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $export = ReportExport::query()->find($this->exportId);

        if ($export !== null && $export->status->isActive()) {
            app(ReportExporter::class)->fail($export, 'worker_failed');
        }
    }
}
