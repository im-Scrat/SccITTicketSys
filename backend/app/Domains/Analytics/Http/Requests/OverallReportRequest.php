<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Http\Requests;

use App\Domains\Analytics\DTOs\OverallReportFilters;
use App\Domains\Analytics\Services\ReportsAccess;
use App\Enums\ComponentType;
use App\Enums\OverallReportSection;
use App\Models\Asset;
use App\Models\PcUnit;
use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * GET /api/admin/reports/overall (Phase 2.10 R4, SDD DD-94): one section of
 * Overall Reports.
 *
 * Authorized for **the section asked for** — its module gate as well as the
 * report gate — before any validation, so a viewer who may not see repairs
 * learns nothing about them, not even from a 422. The equipment filter is a
 * plain `pc:<uuid>` / `asset:<uuid>` string, checked only after authorization.
 */
class OverallReportRequest extends ReportFilterRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user instanceof User) {
            return false;
        }

        $access = app(ReportsAccess::class);

        return in_array($this->section(), $access->visibleSections($user), true)
            && (! $this->narrowsByLocation() || $access->canViewLocations($user))
            && ($this->input('equipment') === null || $access->canViewAssets($user));
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        foreach (['section', 'equipment', 'maintenance_class', 'component_type', 'location_group'] as $key) {
            $raw = $this->input($key);
            if (is_string($raw)) {
                $raw = strtolower(trim($raw));
                $this->merge([$key => $raw === '' || $raw === 'all' ? null : $raw]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraRules(): array
    {
        return [
            'section' => ['nullable', 'string', Rule::in(OverallReportSection::values())],
            'equipment' => ['nullable', 'string', 'regex:/^(pc|asset):[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/'],
            'maintenance_class' => ['nullable', 'string', 'max:64', 'regex:/^(corrective|preventive|upgrade)(,(corrective|preventive|upgrade))*$/'],
            'component_type' => ['nullable', 'string', Rule::in(ComponentType::values())],
            'location_group' => ['nullable', 'string', Rule::in(['room', 'floor', 'building'])],
            ...$this->moreRules(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function moreRules(): array
    {
        return [];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            ...parent::after(),
            function (Validator $validator): void {
                $raw = $this->input('equipment');
                if (! is_string($raw) || $validator->errors()->has('equipment')) {
                    return;
                }
                [$type, $uuid] = explode(':', $raw, 2);
                $exists = $type === 'pc'
                    ? PcUnit::withTrashed()->where('uuid', $uuid)->exists()
                    : Asset::withTrashed()->where('uuid', $uuid)->exists();
                if (! $exists) {
                    $validator->errors()->add('equipment', 'That equipment does not exist.');
                }
            },
        ];
    }

    public function section(): OverallReportSection
    {
        return OverallReportSection::tryFrom((string) $this->input('section')) ?? OverallReportSection::Overview;
    }

    public function overallFilters(): OverallReportFilters
    {
        return OverallReportFilters::fromValidated($this->filters(), $this->validated());
    }
}
