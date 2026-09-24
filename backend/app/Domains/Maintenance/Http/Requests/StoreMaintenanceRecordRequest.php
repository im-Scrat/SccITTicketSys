<?php

declare(strict_types=1);

namespace App\Domains\Maintenance\Http\Requests;

use App\Models\MaintenanceRecord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Open a maintenance record (SRS FR-MNT-001/002).
 *
 * Three things this request deliberately does **not** accept:
 *
 *  - **`status`.** A record is born `scheduled`; every move after that goes
 *    through `MaintenanceLifecycle`. A settable status field is the one
 *    transition nobody validates (SDD DD-43's lesson, restated).
 *  - **`created_by` and every timestamp.** Server-set, always.
 *  - **another technician in `technician`.** An administrator may open work on
 *    someone's behalf; a technician may only open their own. Accepting the field
 *    and then quietly ignoring it would be a form that lies about what it did,
 *    so it is refused explicitly here and enforced again in the action.
 *
 * Targets and the ticket link are addressed by **uuid**, never numeric id
 * (NFR-SEC-001), and existence is checked against live rows — an archived
 * machine is not something new work is scheduled against.
 */
class StoreMaintenanceRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', MaintenanceRecord::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', Rule::exists('maintenance_types', 'slug')->where('is_active', true)],

            // At least one target; the pair is checked in after().
            'pc_unit' => ['nullable', 'uuid', Rule::exists('pc_units', 'uuid')->whereNull('deleted_at')],
            'asset' => ['nullable', 'uuid', Rule::exists('assets', 'uuid')->whereNull('deleted_at')],

            'ticket' => ['nullable', 'uuid', Rule::exists('tickets', 'uuid')->whereNull('deleted_at')],
            'technician' => ['nullable', 'uuid', Rule::exists('users', 'uuid')->whereNull('deleted_at')],

            'scheduled_for' => ['nullable', 'date'],
            'diagnosis' => ['nullable', 'string', 'max:5000'],
            'root_cause' => ['nullable', 'string', 'max:5000'],
            'resolution' => ['nullable', 'string', 'max:5000'],
            'preventive_recommendation' => ['nullable', 'string', 'max:5000'],

            // Mirrors `maintenance_records_metrics_check`, so a negative value
            // is a readable field error rather than a database exception.
            'downtime_minutes' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'labor_hours' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                // The application-side statement of
                // `maintenance_records_target_check`. Without it the constraint
                // would surface as a 500 rather than a field error.
                if ($this->input('pc_unit') === null && $this->input('asset') === null) {
                    $validator->errors()->add('pc_unit', 'Name the PC unit or the asset this maintenance is for.');
                }
            },
            function (Validator $validator): void {
                $technician = $this->input('technician');
                $user = $this->user();

                if ($technician === null || $user === null) {
                    return;
                }

                // Assigning work to someone else is oversight, not fieldwork
                // (Client decision, 2026-08-28). A technician naming themselves
                // is the ordinary case and is fine.
                if ($technician !== $user->uuid && ! $user->can('reassignAny', MaintenanceRecord::class)) {
                    $validator->errors()->add('technician', 'You can only open maintenance assigned to yourself.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'pc_unit' => 'PC unit',
            'type' => 'maintenance type',
        ];
    }
}
