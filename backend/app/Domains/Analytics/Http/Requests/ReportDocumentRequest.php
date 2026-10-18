<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Http\Requests;

use App\Domains\Analytics\Services\ReportBuildOptions;
use App\Domains\Analytics\Services\ReportsAccess;
use App\Enums\ComponentType;
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
    /** Reports with a document builder. */
    public const DOCUMENT_TYPES = [ReportType::TicketOverview, ReportType::Overall];

    /** The overall report's own filters, carried into its documents. */
    public const OVERALL_PARAMETERS = ['equipment', 'maintenance_class', 'component_type', 'location_group'];

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
            ReportType::Overall => $access->visibleSections($user) !== []
                && (! $this->narrowsByLocation() || $access->canViewLocations($user))
                && ($this->input('equipment') === null || $access->canViewAssets($user)),
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
            'include_narrative' => ['sometimes', 'boolean'],
            'equipment' => ['nullable', 'string', 'regex:/^(pc|asset):[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/'],
            'maintenance_class' => ['nullable', 'string', 'max:64', 'regex:/^(corrective|preventive|upgrade)(,(corrective|preventive|upgrade))*$/'],
            'component_type' => ['nullable', 'string', Rule::in(ComponentType::values())],
            'location_group' => ['nullable', 'string', Rule::in(['room', 'floor', 'building'])],
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

    /**
     * The report parameters plus the overall report's own filters.
     *
     * @return array<string, mixed>
     */
    public function reportParameters(): array
    {
        return [
            ...parent::reportParameters(),
            ...array_filter(array_intersect_key($this->validated(), array_flip(self::OVERALL_PARAMETERS)), static fn (mixed $v): bool => $v !== null),
        ];
    }

    public function reportType(): ReportType
    {
        return ReportType::tryFrom((string) $this->input('report_type')) ?? ReportType::TicketOverview;
    }

    /**
     * @return array{paper: string, orientation: string, include_records: bool, include_narrative: bool}
     */
    public function documentOptions(): array
    {
        return [
            'paper' => (string) ($this->validated('paper') ?? 'a4'),
            'orientation' => (string) ($this->validated('orientation') ?? 'portrait'),
            'include_records' => $this->boolean('include_records'),
            'include_narrative' => $this->boolean('include_narrative'),
        ];
    }

    public function buildOptions(ReportContentProfile $profile): ReportBuildOptions
    {
        return new ReportBuildOptions(
            profile: $profile,
            includeRecords: $this->boolean('include_records'),
            recordLimit: (int) config('reports.exports.record_limit', 10000),
            includeNarrative: $this->boolean('include_narrative'),
        );
    }
}
