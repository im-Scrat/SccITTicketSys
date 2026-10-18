<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Http\Requests;

use App\Domains\Analytics\DTOs\ReportFilters;
use App\Domains\Analytics\Exceptions\ReportRangeException;
use App\Domains\Analytics\Services\ReportsAccess;
use App\Enums\ProblemArea;
use App\Enums\ReportComparison;
use App\Enums\ReportGranularity;
use App\Enums\ReportPeriodPreset;
use App\Enums\RoomType;
use App\Models\Building;
use App\Models\Room;
use App\Models\User;
use App\Support\Concerns\ValidatesLookupSlugs;
use App\Support\SchoolClock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The shared report filter vocabulary (Phase 2.10, SDD DD-86/DD-88): period,
 * comparison, grouping, location, status, category and problem area.
 *
 * - **403 before 422.** `authorize()` runs before validation, so a
 *   non-administrator learns nothing from validation messages; and nothing in
 *   {@see prepareForValidation()} touches the database, because it runs before
 *   `authorize()`.
 * - **No existence oracle.** Room and building are plain uuid strings, checked
 *   only after authorization and only when well-formed (`bail`), never bound
 *   to a model by the router.
 * - **Every unknown value is an error**, never silently dropped, so a filter
 *   chip cannot claim a filter that is not applied.
 *
 * Concrete requests may add parameters through {@see extraRules()}.
 */
abstract class ReportFilterRequest extends FormRequest
{
    use ValidatesLookupSlugs;

    /** The instant this request reports up to: one value for validation and the report. */
    private ?CarbonImmutable $asOf = null;

    private ?ReportFilters $filters = null;

    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && app(ReportsAccess::class)->canView($user);
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        foreach (['status', 'category', 'problem_area'] as $key) {
            $raw = $this->input($key);

            if (is_array($raw)) {
                $raw = implode(',', array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $raw));
            }

            if (is_string($raw)) {
                $items = array_values(array_unique(array_filter(array_map(
                    static fn (string $item): string => strtolower(trim($item)),
                    explode(',', $raw),
                ))));
                sort($items);
                $merge[$key] = $items === [] || in_array('all', $items, true) ? null : implode(',', $items);
            }
        }

        foreach (['period', 'anchor', 'from', 'to', 'granularity', 'compare', 'room_type', 'building', 'room'] as $key) {
            $raw = $this->input($key);

            if (is_string($raw)) {
                $raw = trim($raw);
                $merge[$key] = $raw === '' || $raw === 'all' ? null : $raw;
            }
        }

        $this->merge($merge);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $today = SchoolClock::today();

        return [
            'period' => ['nullable', 'string', Rule::in(ReportPeriodPreset::values())],
            'anchor' => [
                'nullable', 'string', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:'.$today->format('Y-m-d'),
                Rule::prohibitedIf(fn (): bool => ! $this->preset()->isCalendar()),
            ],
            'from' => ['nullable', 'required_if:period,custom', 'prohibited_unless:period,custom', 'string', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:'.$today->format('Y-m-d')],
            'to' => ['nullable', 'required_if:period,custom', 'prohibited_unless:period,custom', 'string', 'date_format:Y-m-d', 'before_or_equal:'.$today->format('Y-m-d')],
            'since_year' => ['nullable', 'required_if:period,since_year', 'prohibited_unless:period,since_year', 'integer', 'min:2000', 'max:'.$today->year],
            'granularity' => ['nullable', 'string', Rule::in(ReportGranularity::values())],
            'compare' => ['nullable', 'string', Rule::in(ReportComparison::values())],
            'room_type' => ['nullable', 'string', Rule::in([RoomType::Laboratory->value, RoomType::Office->value])],
            'building' => ['nullable', 'bail', 'string', 'uuid'],
            'room' => ['nullable', 'bail', 'string', 'uuid'],
            'status' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:1000'],
            'problem_area' => ['nullable', 'string', 'max:255'],
            ...$this->extraRules(),
        ];
    }

    /**
     * Parameters a concrete report adds (Overall Reports: equipment, maintenance class, …).
     *
     * @return array<string, mixed>
     */
    protected function extraRules(): array
    {
        return [];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            fn (Validator $v) => $this->validateLookupSlugs($v, 'status', 'ticket_statuses'),
            fn (Validator $v) => $this->validateLookupSlugs($v, 'category', 'ticket_categories'),
            fn (Validator $v) => $this->validateProblemAreas($v),
            fn (Validator $v) => $this->validateCustomRange($v),
            fn (Validator $v) => $this->validateComparison($v),
            fn (Validator $v) => $this->validateLocation($v),
            fn (Validator $v) => $this->validateGrouping($v),
        ];
    }

    /** The validated filters, resolved once against this request's instant. */
    public function filters(): ReportFilters
    {
        return $this->filters ??= ReportFilters::fromValidated($this->validated(), $this->asOf());
    }

    protected function asOf(): CarbonImmutable
    {
        return $this->asOf ??= SchoolClock::asOf();
    }

    /** Whether any location parameter was sent (location blocks need `locations.view`). */
    protected function narrowsByLocation(): bool
    {
        foreach (['room_type', 'building', 'room'] as $key) {
            if (is_string($this->input($key)) && $this->input($key) !== '') {
                return true;
            }
        }

        return false;
    }

    private function preset(): ReportPeriodPreset
    {
        return ReportPeriodPreset::tryFrom((string) ($this->input('period') ?? ReportPeriodPreset::Month->value)) ?? ReportPeriodPreset::Month;
    }

    private function validateProblemAreas(Validator $validator): void
    {
        $raw = $this->input('problem_area');

        if (! is_string($raw) || $raw === '') {
            return;
        }

        foreach (explode(',', $raw) as $area) {
            if (ProblemArea::tryFrom($area) === null) {
                $validator->errors()->add('problem_area', "\"{$area}\" is not a valid problem area.");
            }
        }
    }

    private function validateCustomRange(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['from', 'to']) || $this->preset() !== ReportPeriodPreset::Custom) {
            return;
        }

        $from = (string) $this->input('from');
        $to = (string) $this->input('to');

        if ($to < $from) {
            $validator->errors()->add('to', 'The end date must be on or after the start date.');

            return;
        }

        $days = SchoolClock::startOfLocalDay($from)->diffInDays(SchoolClock::startOfLocalDay($to)) + 1;

        if ($days > 7320) {
            $validator->errors()->add('to', 'A custom range can cover at most 20 years.');
        }
    }

    private function validateComparison(Validator $validator): void
    {
        $compare = $this->input('compare');

        if ($this->preset() === ReportPeriodPreset::SinceYear && is_string($compare) && $compare !== ReportComparison::None->value) {
            $validator->errors()->add('compare', "Comparison isn't available for \"Since a year\".");
        }
    }

    private function validateLocation(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['building', 'room', 'room_type'])) {
            return;
        }

        $buildingUuid = $this->input('building');
        $roomUuid = $this->input('room');

        if (is_string($buildingUuid) && ! Building::withTrashed()->where('uuid', $buildingUuid)->exists()) {
            $validator->errors()->add('building', 'That building does not exist.');

            return;
        }

        if (! is_string($roomUuid)) {
            return;
        }

        $room = Room::withTrashed()->with(['floor' => fn ($q) => $q->withTrashed()])->where('uuid', $roomUuid)->first();

        if ($room === null) {
            $validator->errors()->add('room', 'That room does not exist.');

            return;
        }

        if (is_string($buildingUuid)) {
            $buildingId = Building::withTrashed()->where('uuid', $buildingUuid)->value('id');

            if ($room->floor?->building_id !== $buildingId) {
                $validator->errors()->add('room', 'That room is not in the selected building.');
            }
        }

        $roomType = $this->input('room_type');

        if (is_string($roomType) && $room->room_type->value !== $roomType) {
            $validator->errors()->add('room', 'That room is not of the selected type.');
        }
    }

    private function validateGrouping(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        try {
            $this->filters = ReportFilters::fromValidated($validator->validated(), $this->asOf());
        } catch (ReportRangeException $e) {
            $validator->errors()->add('granularity', $e->getMessage());
        }
    }
}
