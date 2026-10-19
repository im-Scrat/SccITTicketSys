<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Console;

use App\Enums\ReportEmailDeliveryStatus;
use App\Enums\ReportExportStatus;
use App\Models\ReportEmailDelivery;
use App\Models\ReportExport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * `reports:prune-exports` — daily report housekeeping (Phase 2.10, SDD DD-91/DD-92).
 *
 *  1. Deletes files past `reports.exports.retention_hours` and marks their rows
 *     `expired` (the row stays: it is the record that the file existed).
 *  2. Marks email copies still queued after `reports.email.stale_after_hours`
 *     as failed (`stale`), so nothing says "Queued" forever.
 *  3. After `reports.exports.row_retention_days`, nulls the request IP and user
 *     agent on old rows and deletes old delivery rows (the audit log keeps the
 *     request itself).
 *
 * Counts only in its output. Idempotent; safe to run at any time.
 */
class PruneReportExports extends Command
{
    protected $signature = 'reports:prune-exports';

    protected $description = 'Delete expired report files and tidy old report rows';

    public function handle(): int
    {
        $expired = 0;

        ReportExport::query()
            ->where('status', ReportExportStatus::Completed->value)
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function ($exports) use (&$expired): void {
                foreach ($exports as $export) {
                    if ($export->path !== null) {
                        Storage::disk($export->disk)->deleteDirectory(dirname($export->path));
                    }
                    $export->forceFill(['status' => ReportExportStatus::Expired, 'path' => null])->save();
                    $expired++;
                }
            });

        $stale = ReportEmailDelivery::query()
            ->where('status', ReportEmailDeliveryStatus::Queued->value)
            ->where('created_at', '<', now()->subHours((int) config('reports.email.stale_after_hours', 24)))
            ->update([
                'status' => ReportEmailDeliveryStatus::Failed->value,
                'failure_code' => 'stale',
                'failed_at' => now(),
                'updated_at' => now(),
            ]);

        $cutoff = now()->subDays((int) config('reports.exports.row_retention_days', 180));
        $anonymised = ReportExport::query()
            ->where('created_at', '<', $cutoff)
            ->where(fn ($q) => $q->whereNotNull('request_ip')->orWhereNotNull('request_user_agent'))
            ->update(['request_ip' => null, 'request_user_agent' => null, 'updated_at' => now()]);
        $deliveries = ReportEmailDelivery::query()->where('created_at', '<', $cutoff)->delete();

        $this->info(sprintf(
            'Expired %d report files; marked %d stale email copies failed; anonymised %d old requests; removed %d old delivery rows.',
            $expired, $stale, $anonymised, $deliveries,
        ));

        return self::SUCCESS;
    }
}
