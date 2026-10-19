<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Http\Requests;

use App\Enums\ReportExportFormat;
use Illuminate\Validation\Rule;

/**
 * POST /api/admin/reports/exports (Phase 2.10, SDD DD-91): queue a document
 * for download.
 */
class StoreReportExportRequest extends ReportDocumentRequest
{
    /**
     * @return array<string, mixed>
     */
    protected function documentRules(): array
    {
        return [
            'format' => ['required', 'string', Rule::in(ReportExportFormat::values())],
        ];
    }

    public function exportFormat(): ReportExportFormat
    {
        return ReportExportFormat::from((string) $this->validated('format'));
    }
}
