<?php

declare(strict_types=1);

namespace App\Domains\Assets\Http\Requests;

use App\Models\AssetImport;
use Illuminate\Foundation\Http\FormRequest;

/** Search the estate's rooms for a location decision (Phase 2.10 R6). */
class ImportRoomOptionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('viewAny', AssetImport::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
        ];
    }
}
