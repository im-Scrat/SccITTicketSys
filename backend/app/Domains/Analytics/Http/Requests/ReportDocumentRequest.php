<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Http\Requests;

use App\Domains\Analytics\Services\ReportBuildOptions;
use App\Domains\Analytics\Services\ReportsAccess;
use App\Enums\ReportContentProfile;
use App\Enums\ReportType;
use App\Models\User;
use Illuminate\Validation\Rule;

/**
 * A request to turn a report into a document — export, email or print
 * (Phase 2.10, SDD DD-91). The report's own filters, exactly as the screen
 * sends them, plus what to build.
 *
 * Authorization is the export gate (`reports.export`) **and** the report's own
 * domain gates, so a document can never show what its screen would not; it
 * answers 403 before any validation message, like every report request.
 */
abstract class ReportDocumentRequest extends ReportFilterRequest
{
    /** Reports with a document builder today; the overall report joins in R4. */
    public const DOCUMENT_TYPES = [ReportType::TicketOverview];

    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user instanceof User) {
            return false;
        }

        $access = app(ReportsAccess::class);

        if (! $access->canExport($user)) {
            return false;
        }

        return match ($this->reportType()) {
            ReportType::TicketOverview => $access->canViewTickets($user)
                && (! $this->narrowsByLocation() || $access->canViewLocations($user)),
            default => true,
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraRules(): array
    {
        return [
            'report_type' => ['required', 'string', Rule::in(array_map(static fn (ReportType $t): string => $t->value, self::DOCUMENT_TYPES))],
            'paper' => ['nullable', 'string', Rule::in(['a4', 'letter'])],
            'orientation' => ['nullable', 'string', Rule::in(['portrait', 'landscape'])],
            'include_records' => ['sometimes', 'boolean'],
            ...$this->documentRules(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function documentRules(): array
    {
        return [];
    }

    public function reportType(): ReportType
    {
        return ReportType::tryFrom((string) $this->input('report_type')) ?? ReportType::TicketOverview;
    }

    /**
     * @return array{paper: string, orientation: string, include_records: bool}
     */
    public function documentOptions(): array
    {
        return [
            'paper' => (string) ($this->validated('paper') ?? 'a4'),
            'orientation' => (string) ($this->validated('orientation') ?? 'portrait'),
            'include_records' => $this->boolean('include_records'),
        ];
    }

    public function buildOptions(ReportContentProfile $profile): ReportBuildOptions
    {
        return new ReportBuildOptions(
            profile: $profile,
            includeRecords: $this->boolean('include_records'),
            recordLimit: (int) config('reports.exports.record_limit', 10000),
        );
    }
}
