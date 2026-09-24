<?php

declare(strict_types=1);

namespace App\Domains\Maintenance\Http\Requests;

use App\Models\MaintenanceRecord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edit an open maintenance record's detail (SRS FR-MNT-003).
 *
 * Narrower than the store request on purpose: **the target cannot be changed.**
 * A record is the account of work on one machine, and repointing it would
 * silently rewrite two service histories at once — the machine that loses the
 * visit and the machine that gains one it never had. If the wrong machine was
 * named, the record is cancelled and a correct one opened, which leaves both
 * facts visible in the timeline.
 *
 * `status` is absent here too; it moves only through `MaintenanceLifecycle`.
 * Reassignment is its own endpoint, because it is an administrator's action
 * rather than an edit.
 */
class UpdateMaintenanceRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        $record = $this->route('record');

        return $record instanceof MaintenanceRecord
            && (bool) $this->user()?->can('update', $record);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'type' => ['sometimes', 'required', 'string', Rule::exists('maintenance_types', 'slug')->where('is_active', true)],
            'ticket' => ['sometimes', 'nullable', 'uuid', Rule::exists('tickets', 'uuid')->whereNull('deleted_at')],

            'scheduled_for' => ['sometimes', 'nullable', 'date'],
            'reschedule_reason' => ['nullable', 'string', 'max:500'],

            'diagnosis' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'root_cause' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'resolution' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'preventive_recommendation' => ['sometimes', 'nullable', 'string', 'max:5000'],

            'downtime_minutes' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'],
            'labor_hours' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999'],
            'cost' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['type' => 'maintenance type'];
    }
}
