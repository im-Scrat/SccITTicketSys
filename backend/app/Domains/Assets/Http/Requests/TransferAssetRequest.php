<?php

declare(strict_types=1);

namespace App\Domains\Assets\Http\Requests;

use App\Models\Asset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates an asset transfer between rooms (SRS FR-AST-006).
 *
 * `room` is nullable on purpose: moving an asset *out* of a room without naming
 * a destination is a real operation — equipment goes to a holding area that is
 * not modelled as a room. The transfer ledger records the null destination
 * honestly rather than pretending the asset never moved.
 */
class TransferAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        $asset = $this->route('asset');

        return $asset instanceof Asset && (bool) $this->user()?->can('transfer', $asset);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'room' => ['present', 'nullable', 'string', 'uuid', Rule::exists('rooms', 'uuid')->whereNull('deleted_at')],
            'reason' => ['nullable', 'string', 'max:2000'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'room.present' => 'Specify the destination room, or null to remove this asset from its room.',
            'room.exists' => 'Choose an available room.',
        ];
    }
}
