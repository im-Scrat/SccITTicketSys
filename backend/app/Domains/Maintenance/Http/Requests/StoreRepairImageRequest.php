<?php

declare(strict_types=1);

namespace App\Domains\Maintenance\Http\Requests;

use App\Enums\RepairImageType;
use App\Models\MaintenanceRecord;
use App\Support\Attachments\AttachmentSecurity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Upload one item of repair evidence (SRS FR-MNT-005/010; SDD DD-53).
 *
 * The file rules are **built from {@see AttachmentSecurity}**, never restated
 * here. A hand-written `mimes:` list is how the ticket and asset paths drifted
 * apart in the first place; deriving them means the profile is the single place
 * a type is added or removed, and the rule and the detection can never disagree.
 *
 * `manageEvidence` is re-checked here as well as at the route, so the narrower
 * rule — the visit must be open and the record must be this caller's — still
 * holds even though the route gate is only `maintenance.update`.
 */
class StoreRepairImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $record = $this->route('record');

        return $record instanceof MaintenanceRecord
            && (bool) $this->user()?->can('manageEvidence', $record);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => AttachmentSecurity::rules(AttachmentSecurity::PROFILE_MAINTENANCE),
            'image_type' => ['required', 'string', Rule::in(RepairImageType::values())],
            'caption' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['image_type' => 'evidence stage'];
    }
}
