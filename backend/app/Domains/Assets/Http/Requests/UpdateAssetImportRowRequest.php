<?php

declare(strict_types=1);

namespace App\Domains\Assets\Http\Requests;

use App\Models\AssetImport;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Correct, acknowledge or exclude one review row (Phase 2.10 R6). A
 * correction value of `null` removes the correction; `room` takes
 * `{uuid, assign_anyway}`.
 */
class UpdateAssetImportRowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('manage', AssetImport::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'corrections' => ['nullable', 'array', 'max:60'],
            'corrections.room' => ['nullable', 'array'],
            'corrections.room.uuid' => ['nullable', 'string', 'max:64'],
            'corrections.room.assign_anyway' => ['nullable', 'boolean'],
            'acknowledge' => ['nullable', 'array', 'max:20'],
            'acknowledge.*' => ['string', 'max:64'],
            'exclude' => ['nullable', 'boolean'],
        ];
    }
}
