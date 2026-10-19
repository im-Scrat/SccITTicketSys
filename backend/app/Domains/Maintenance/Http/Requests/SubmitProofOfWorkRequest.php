<?php

declare(strict_types=1);

namespace App\Domains\Maintenance\Http\Requests;

use App\Domains\Maintenance\Actions\SubmitProofOfWork;
use App\Enums\RepairImageType;
use App\Support\Attachments\AttachmentSecurity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * Submit proof that work was performed on a scanned unit
 * (SRS FR-MNT-009/010; SDD DD-50).
 *
 * ── What this class does *not* decide ──────────────────────────────────────
 *
 * Which maintenance record the proof lands on, whether the actor may work it,
 * whether the record may be completed, and whether enough evidence exists are
 * **all** decided later, inside the action's transaction. They are invariants of
 * the record, not of one HTTP shape — the position `MaintenanceLifecycle` takes
 * for its own gates, and for the same reason: a rule enforced here would be
 * skipped by any caller that is not this endpoint.
 *
 * There is deliberately no `pc_unit` field. The machine comes from the scanned
 * code, resolved server-side; a client-supplied unit identifier would be exactly
 * the "manipulate the identifier to reach another PC" vector the workflow is
 * built to refuse.
 *
 * ── The file rules are derived, never restated ─────────────────────────────
 *
 * `evidence.*` is built from {@see AttachmentSecurity::rules()} on the
 * **maintenance** profile — the same call {@see StoreRepairImageRequest} makes.
 * A hand-written `mimes:` list here would be a second upload contract, which is
 * the one thing FR-MNT-010 forbids outright: *"No second upload path shall be
 * introduced for maintenance evidence."*
 */
class SubmitProofOfWorkRequest extends FormRequest
{
    /**
     * The permission floor, restated.
     *
     * The route already carries `can:maintenance.update`. Repeating it costs
     * nothing and means the rule survives a route being regrouped — the same
     * belt-and-braces `StoreRepairImageRequest` uses. It is only a floor:
     * *whose* record may be written is settled per-record, later.
     */
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermissionTo('maintenance.update');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // The physical scan this proof belongs to (FR-MNT-012 L1). Existence,
            // ownership and freshness are checked in the controller, not here:
            // an `exists:` rule would answer "is this a real scan id?" to anyone
            // who asked, one guess at a time.
            'scan_id' => ['required', 'uuid'],

            // Optional, and required in practice only when the machine carries
            // more than one job this technician may work — at which point the
            // action refuses and returns the candidates to choose from.
            'maintenance_id' => ['nullable', 'uuid'],

            'resolution' => ['required', 'string', 'min:3', 'max:5000'],
            'diagnosis' => ['nullable', 'string', 'max:5000'],
            'root_cause' => ['nullable', 'string', 'max:5000'],

            'outcome' => ['required', 'string', Rule::in(SubmitProofOfWork::OUTCOMES)],

            'evidence' => ['nullable', 'array', 'max:10'],
            'evidence.*' => AttachmentSecurity::rules(AttachmentSecurity::PROFILE_MAINTENANCE),

            // Required alongside files rather than defaulted: `before`, `during`
            // and `after` are assertions about when a photograph was taken, and
            // the server guessing one would put a claim in the technician's
            // mouth.
            'evidence_type' => [
                Rule::requiredIf(fn (): bool => $this->hasFile('evidence')),
                'string',
                Rule::in(RepairImageType::values()),
            ],
            'caption' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * The uploads, as a plain list.
     *
     * `file('evidence')` returns null, one file, or an array depending on what
     * arrived; normalising it here keeps that shape question out of the action.
     *
     * @return list<UploadedFile>
     */
    public function evidenceFiles(): array
    {
        $files = $this->file('evidence');

        if ($files === null) {
            return [];
        }

        return array_values(is_array($files) ? $files : [$files]);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'scan_id' => 'scan',
            'maintenance_id' => 'maintenance record',
            'evidence_type' => 'evidence stage',
            'outcome' => 'outcome',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'resolution.required' => 'Describe what you did before submitting proof.',
            'outcome.in' => 'Choose whether the work is continuing, on hold, or complete.',
        ];
    }
}
