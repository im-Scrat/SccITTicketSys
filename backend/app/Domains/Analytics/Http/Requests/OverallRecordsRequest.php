<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Http\Requests;

use App\Domains\Analytics\Services\OverallRecords;
use App\Enums\OverallReportSection;
use App\Enums\ReportRecordsTable;
use Illuminate\Validation\Rule;

/**
 * GET /api/admin/reports/overall/records (Phase 2.10 R4): one page of a record
 * table, authorized by the section the table belongs to (ticket records need
 * the tickets module, repair records the maintenance module, and so on).
 */
class OverallRecordsRequest extends OverallReportRequest
{
    public function section(): OverallReportSection
    {
        return ReportRecordsTable::tryFrom((string) $this->input('table'))?->section() ?? OverallReportSection::Tickets;
    }

    /**
     * @return array<string, mixed>
     */
    protected function moreRules(): array
    {
        return [
            'table' => ['required', 'string', Rule::in(ReportRecordsTable::values())],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.OverallRecords::MAX_PER_PAGE],
            'sort' => ['nullable', 'string', 'max:32'],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
        ];
    }

    public function table(): ReportRecordsTable
    {
        return ReportRecordsTable::from((string) $this->validated('table'));
    }
}
