<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Http\Resources;

use App\Models\AiPrediction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AiPrediction
 */
class AiPredictionListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'pc_unit' => $this->pcUnit !== null ? [
                'id' => $this->pcUnit->uuid,
                'label' => $this->pcUnit->pc_name,
                'identifier' => $this->pcUnit->unit_code,
            ] : null,
            'predicted_issue' => $this->predicted_issue,
            'risk_level' => $this->risk_level !== null ? [
                'value' => $this->risk_level->value,
                'label' => $this->risk_level->label(),
                'tone' => $this->risk_level->tone(),
            ] : null,
            'confidence' => $this->confidence !== null ? (float) $this->confidence : null,
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
            ],
            'generated_at' => $this->generated_at?->toIso8601String(),
        ];
    }
}
