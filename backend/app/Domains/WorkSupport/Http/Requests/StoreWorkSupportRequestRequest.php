<?php

declare(strict_types=1);

namespace App\Domains\WorkSupport\Http\Requests;

use App\Models\WorkSupportRequest;
use App\Support\Attachments\AttachmentSecurity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * Submit a work support request (SRS FR-WSR-001/002/003).
 *
 * ── What is deliberately absent ────────────────────────────────────────────
 *
 * No `pc_unit`, no `technician_id`, no `ticket_id`. The machine comes from the
 * scanned code in the route, the technician from the session, and the ticket
 * from the maintenance record the work belongs to. A client-supplied identifier
 * for any of the three would be the "manipulate an identifier to reach another
 * record" vector this workflow exists to refuse — so there is no field to
 * manipulate.
 *
 * `maintenance_id` **is** accepted, because a machine can carry more than one
 * job and only the technician knows which one needs the part. It is a *choice
 * among rows the caller already has*, re-resolved server-side through
 * `ScannedWorkTargets`; naming someone else's is refused there, not here.
 *
 * File rules are built from {@see AttachmentSecurity}, never restated — the
 * FR-WSR-003 requirement is the single attachment boundary, and a hand-written
 * `mimes:` list here would be a second one.
 */
class StoreWorkSupportRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', WorkSupportRequest::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'maintenance_id' => ['nullable', 'uuid'],

            // FR-WSR-002: a written explanation of *why* the job needs it. The
            // database independently refuses a blank one (DR-020).
            'explanation' => ['required', 'string', 'min:10', 'max:5000'],

            // FR-WSR-002: "one or more line items". An empty array is refused
            // here and again in the action.
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.hardware_model' => [
                'nullable',
                'integer',
                Rule::exists('hardware_models', 'id')->whereNull('deleted_at'),
            ],
            'items.*.description' => ['nullable', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'items.*.remarks' => ['nullable', 'string', 'max:500'],

            'evidence' => ['nullable', 'array', 'max:5'],
            'evidence.*' => AttachmentSecurity::rules(AttachmentSecurity::PROFILE_MAINTENANCE),
            'caption' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * "A catalogue model **or** a free-text description" — the database CHECK
     * in words, restated here so the technician is told which line is at fault
     * rather than meeting a constraint violation.
     */
    public function withValidator(mixed $validator): void
    {
        $validator->after(function ($validator): void {
            /** @var array<int, array<string, mixed>> $items */
            $items = $this->input('items', []);

            foreach ($items as $index => $item) {
                $named = ($item['hardware_model'] ?? null) !== null
                    || trim((string) ($item['description'] ?? '')) !== '';

                if (! $named) {
                    $validator->errors()->add(
                        "items.{$index}.description",
                        'Say what this item is — pick a catalog model or describe it.',
                    );
                }
            }
        });
    }

    /**
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
            'maintenance_id' => 'maintenance record',
            'explanation' => 'explanation',
            'items' => 'requested items',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'explanation.required' => 'Explain what the job needs and why it cannot be finished without it.',
            'explanation.min' => 'Give enough detail for an administrator to decide — a few words is not a request.',
            'items.required' => 'Name at least one thing the job needs.',
            'items.min' => 'Name at least one thing the job needs.',
        ];
    }
}
