<?php

declare(strict_types=1);

namespace App\Domains\Assets\Http\Requests;

use App\Enums\AssetStatus;
use App\Models\Asset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a lifecycle status change (SRS FR-AST-005).
 *
 * Authorization is **target-aware**: moving an asset to `retired` or `disposed`
 * writes the equipment off, so it requires `assets.dispose` rather than
 * `assets.update` (SDD DD-33). The target status is therefore resolved here and
 * passed into the policy, which is why this request cannot use the plain
 * `can:assets.update` route gate alone.
 *
 * Whether the transition is *legal* is a separate question, answered by
 * `AssetLifecycle` with its own 422 naming the reachable states.
 */
class ChangeAssetStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $asset = $this->route('asset');

        if (! $asset instanceof Asset) {
            return false;
        }

        $target = AssetStatus::tryFrom((string) $this->input('status'));

        return (bool) $this->user()?->can('changeStatus', [$asset, $target]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(AssetStatus::values())],
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.in' => 'That is not a recognized asset status.',
        ];
    }

    /** The validated target, resolved once for the controller. */
    public function target(): AssetStatus
    {
        return AssetStatus::from((string) $this->validated('status'));
    }
}
