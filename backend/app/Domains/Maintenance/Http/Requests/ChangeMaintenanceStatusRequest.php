<?php

declare(strict_types=1);

namespace App\Domains\Maintenance\Http\Requests;

use App\Domains\Maintenance\Services\MaintenanceLifecycle;
use App\Enums\MaintenanceStatus;
use App\Models\MaintenanceRecord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Move a maintenance record through its lifecycle
 * (SRS FR-MNT-003/004/008/010).
 *
 * This validates only the *shape* — that `status` names one of the five legal
 * values at all. **Whether the move is legal from where the record stands, and
 * whether the completion gates are met, belongs to
 * {@see MaintenanceLifecycle}**, which answers 422 with either the reachable
 * set or the specific blockers.
 *
 * Keeping the two apart matters: those gates are invariants of the record, not
 * of this HTTP shape. WP-2.6b's scanned submission will complete a record
 * without passing through this class, and it must meet exactly the same wall.
 */
class ChangeMaintenanceStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $record = $this->route('record');

        return $record instanceof MaintenanceRecord
            && (bool) $this->user()?->can('transition', $record);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(MaintenanceStatus::values())],
            // Written onto the record when completing, so a technician finishes
            // the job in one action rather than saving and then closing.
            'resolution' => ['nullable', 'string', 'max:5000'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
