<?php

declare(strict_types=1);

namespace App\Domains\Assets\Http\Requests;

use App\Domains\Assets\Rules\AssetCreationRules;
use App\Models\Asset;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates asset creation (SRS FR-AST-002).
 *
 * The rules mirror the database constraints rather than replacing them: the
 * `assets_warranty_check` and `assets_purchase_price_check` CHECKs remain the
 * last line of defense (SDD DD-08), and these give the operator a readable
 * message before the write is attempted.
 *
 * Rooms and technicians are addressed by uuid; the catalog model by id, which
 * only ever travels inside this payload and never in a URL.
 *
 * The rules live in {@see AssetCreationRules} (Phase 2.10 R6), shared with the asset import.
 */
class StoreAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', Asset::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // The form's narrowed options; the import uses AssetCreationRules::rules().
        return AssetCreationRules::formRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [...AssetCreationRules::messages(), ...AssetCreationRules::otherMessages()];
    }

    protected function prepareForValidation(): void
    {
        // Only the identifiers the rules normalise; nothing else is rewritten.
        $this->merge(array_intersect_key(AssetCreationRules::normalize($this->all()), array_flip(['asset_tag'])));
    }
}
