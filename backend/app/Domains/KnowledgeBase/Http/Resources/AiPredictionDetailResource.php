<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Http\Resources;

use App\Domains\KnowledgeBase\Services\PcHistoryReview;
use App\Domains\KnowledgeBase\Services\PcRiskAssessor;
use App\Models\AiPrediction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One predictive-maintenance finding, in full (WP-M mandate's field list:
 * PC, asset, location, prior problems, completed-repair count, repeating
 * problems, recent repair, components replaced, detected pattern, risk,
 * predicted issue, time window, explanation/evidence, confidence,
 * recommendation).
 *
 * `evidence` is served exactly as {@see PcRiskAssessor}
 * froze it at generation time — the observed facts and detected pattern(s)
 * the model was actually shown — rather than re-derived fresh from the PC's
 * *current* history. A later repair changing what the history now shows must
 * not silently rewrite what justified a finding already made; PC identity and
 * location are the only parts read live, because "where is this machine now"
 * is not part of the prediction's own reasoning.
 *
 * @mixin AiPrediction
 */
class AiPredictionDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $pcUnit = $this->pcUnit;
        $room = $pcUnit?->room;
        $floor = $room?->floor;
        $building = $floor?->building;

        return [
            'id' => $this->uuid,
            'pc_unit' => $pcUnit !== null ? [
                'id' => $pcUnit->uuid,
                'label' => $pcUnit->pc_name,
                'identifier' => $pcUnit->unit_code,
                'asset_tag' => $pcUnit->asset_tag,
            ] : null,
            'location' => $room !== null ? [
                'room' => $room->name,
                'floor' => $floor?->name,
                'building' => $building?->name,
            ] : null,

            'predicted_issue' => $this->predicted_issue,
            'risk_level' => $this->risk_level !== null ? [
                'value' => $this->risk_level->value,
                'label' => $this->risk_level->label(),
                'tone' => $this->risk_level->tone(),
            ] : null,
            // Never populated by this pipeline today — no calibrated failure
            // model exists — served honestly as null rather than omitted, so
            // the client never has to guess whether it was left out.
            'probability' => $this->probability !== null ? (float) $this->probability : null,
            'confidence' => $this->confidence !== null ? (float) $this->confidence : null,
            'predicted_within_days' => $this->predicted_within_days,
            'explanation' => $this->explanation,
            'recommendation' => $this->recommendation,
            'evidence' => $this->evidence,

            // The machine as it stands *now* — deliberately a separate key from
            // `evidence`, which stays frozen at generation. See PcHistoryReview.
            'history' => $pcUnit !== null ? app(PcHistoryReview::class)->for($pcUnit) : null,

            'ai_model' => $this->aiModel !== null ? [
                'provider' => $this->aiModel->provider,
                'model' => $this->aiModel->model_identifier,
            ] : null,
            'failure_pattern' => $this->failurePattern !== null ? [
                'id' => $this->failurePattern->id,
                'name' => $this->failurePattern->pattern_name,
                'occurrence_count' => $this->failurePattern->occurrence_count,
            ] : null,

            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
            ],
            'can' => [
                'decide' => $request->user()?->can('manage', $this->resource) ?? false,
            ],

            'generated_at' => $this->generated_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
