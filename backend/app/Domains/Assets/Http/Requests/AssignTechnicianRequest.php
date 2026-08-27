<?php

declare(strict_types=1);

namespace App\Domains\Assets\Http\Requests;

use App\Models\Asset;
use App\Models\Role;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates asset custodianship (SRS FR-AST-002).
 *
 * `technician` is nullable so the same endpoint hands an asset over and takes it
 * back; the Action audits the two cases as different events.
 *
 * The candidate must hold a **technician or administrator** role. Restricting it
 * at validation time — rather than trusting the client's dropdown — is what
 * stops an asset being booked out to a teacher, whether by a stale UI or a
 * hand-crafted request.
 */
class AssignTechnicianRequest extends FormRequest
{
    public function authorize(): bool
    {
        $asset = $this->route('asset');

        return $asset instanceof Asset && (bool) $this->user()?->can('assignTechnician', $asset);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'technician' => [
                'present',
                'nullable',
                'string',
                'uuid',
                // The role restriction is a subquery, so it uses the closure form
                // of `where` — `whereIn` documents its second argument as an
                // array, and passing a builder there only works by accident.
                Rule::exists('users', 'uuid')
                    ->whereNull('deleted_at')
                    ->where(fn (Builder $query): Builder => $query->whereIn(
                        'role_id',
                        Role::query()->select('id')->whereIn('slug', ['technician', 'administrator']),
                    )),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'technician.present' => 'Specify a staff member, or null to unassign this asset.',
            'technician.exists' => 'Assets can only be assigned to a technician or an administrator.',
        ];
    }
}
