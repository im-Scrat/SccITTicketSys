<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Toggle the force-password-reset requirement (SRS FR-USER admin action). When
 * `required` is omitted it defaults to true (set the requirement).
 */
class ForcePasswordResetRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('user');

        return $target instanceof User && (bool) $this->user()?->can('resetPassword', $target);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'required' => ['sometimes', 'boolean'],
        ];
    }

    public function requiredFlag(): bool
    {
        return $this->boolean('required', true);
    }
}
