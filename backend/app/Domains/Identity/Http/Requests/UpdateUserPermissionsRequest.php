<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Per-user permission override input (SRS FR-USER-004). The request carries the
 * complete desired override set: `grants` (force-allow) and `denies`
 * (force-deny). A permission may not appear in both.
 */
class UpdateUserPermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('user');

        return $target instanceof User && (bool) $this->user()?->can('managePermissions', $target);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'grants' => ['present', 'array'],
            'grants.*' => ['string', Rule::exists('permissions', 'name')],
            'denies' => ['present', 'array'],
            'denies.*' => ['string', Rule::exists('permissions', 'name')],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $overlap = array_intersect(
                (array) $this->input('grants', []),
                (array) $this->input('denies', []),
            );

            if ($overlap !== []) {
                $validator->errors()->add('grants', 'A permission cannot be both granted and denied: '.implode(', ', $overlap).'.');
            }
        });
    }
}
