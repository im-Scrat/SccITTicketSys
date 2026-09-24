<?php

declare(strict_types=1);

namespace App\Domains\Maintenance\Http\Requests;

use App\Enums\MaintenanceStatus;
use App\Models\MaintenanceRecord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates maintenance list parameters for the technician queue, the history
 * list, the preventive horizon and the Administrator directory
 * (SRS FR-MNT-003/007/011).
 *
 * The **row scoping is not here** — `MaintenanceVisibility` applies it
 * unconditionally inside every query, so no parameter this request accepts can
 * widen what the caller sees. `scope=all` is the clearest case: it is accepted
 * from anyone and honoured for nobody who is not an administrator.
 *
 * What this does is keep the *shape* honest. Sort keys are allow-listed so a
 * crafted value never reaches an `orderBy`, and an unrecognized status or type
 * slug is **rejected with 422 rather than silently dropped** — a filter chip
 * that claims to be filtering while the list is unfiltered is worse than an
 * error (the rule established in Phase 2.5 and reaffirmed in 2.6).
 */
class IndexMaintenanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('viewAny', MaintenanceRecord::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:255'],
            'preventive' => ['nullable', 'boolean'],
            'technician' => ['nullable', 'string', 'uuid'],
            'pc_unit' => ['nullable', 'string', 'uuid'],
            'asset' => ['nullable', 'string', 'uuid'],
            'ticket' => ['nullable', 'string', 'uuid'],
            'room' => ['nullable', 'string', 'uuid'],
            'building' => ['nullable', 'string', 'uuid'],
            'scheduled_from' => ['nullable', 'date'],
            'scheduled_to' => ['nullable', 'date', 'after_or_equal:scheduled_from'],
            'overdue' => ['nullable', 'boolean'],
            'scope' => ['nullable', 'string', Rule::in(['own', 'all'])],
            'trashed' => ['nullable', 'string', Rule::in(['without', 'with', 'only'])],
            'sort' => ['nullable', 'string', Rule::in([
                'created_at', 'updated_at', 'title', 'scheduled_for', 'started_at',
                'completed_at', 'maintenance_date', 'downtime', 'cost', 'status',
                'type', 'technician',
            ])],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            fn (Validator $v) => $this->validateStatuses($v),
            fn (Validator $v) => $this->validateTypes($v),
        ];
    }

    /**
     * `status` is an enum rather than a lookup table, so it is checked against
     * the enum — which is the same source the CHECK constraint is built from.
     */
    private function validateStatuses(Validator $validator): void
    {
        foreach ($this->submitted('status') as $value) {
            if (MaintenanceStatus::tryFrom($value) === null) {
                $validator->errors()->add('status', "\"{$value}\" is not a valid status.");
            }
        }
    }

    private function validateTypes(Validator $validator): void
    {
        $submitted = $this->submitted('type');

        if ($submitted === []) {
            return;
        }

        $known = DB::table('maintenance_types')->whereIn('slug', $submitted)->pluck('slug')->all();

        foreach (array_diff($submitted, $known) as $unknown) {
            $validator->errors()->add('type', "\"{$unknown}\" is not a valid maintenance type.");
        }
    }

    /**
     * @return list<string>
     */
    private function submitted(string $field): array
    {
        $raw = $this->input($field);

        if (! is_string($raw) || $raw === '' || $raw === 'all') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }
}
