<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Http\Controllers\Admin;

use App\Domains\KnowledgeBase\Http\Resources\AiPredictionDetailResource;
use App\Domains\KnowledgeBase\Http\Resources\AiPredictionListResource;
use App\Domains\KnowledgeBase\Services\PcPredictionAccess;
use App\Domains\KnowledgeBase\Services\PcPredictionDecision;
use App\Enums\PredictionRiskLevel;
use App\Enums\PredictionStatus;
use App\Http\Controllers\Controller;
use App\Models\AiPrediction;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * The Administrator's predictive-maintenance review surface (WP-M; SRS
 * FR-AI-011). Thin controller: the route's own `can:viewAny,AiPrediction` /
 * `can:manage,AiPrediction` middleware is the boundary — see
 * {@see PcPredictionAccess} for why it
 * must be the policy ability and never a bare `can:predictions.view` string
 * — and every method re-checks per instance besides, the same defence in
 * depth every other admin-only write surface in this codebase carries.
 *
 * `{prediction}` arrives as a plain string, not a route-bound model: implicit
 * binding runs before the `can` middleware, and a bound model would answer an
 * unauthorized caller with a 404 for an unknown uuid and a 403 for a real
 * one — an existence oracle. Resolving after authorization, the same pattern
 * `FloorPlanController` and `RoomLayoutController` use, gives the same 403
 * either way.
 */
class AiPredictionController extends Controller
{
    public function __construct(private readonly PcPredictionDecision $decisions) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', AiPrediction::class);

        $validated = $request->validate([
            'status' => ['sometimes', 'string', Rule::in(PredictionStatus::values())],
            'risk_level' => ['sometimes', 'string', Rule::in(PredictionRiskLevel::values())],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = AiPrediction::query()
            ->with('pcUnit')
            // Pending first: what needs a decision leads; history trails behind.
            ->orderByRaw("status = 'pending' desc")
            ->orderByDesc('generated_at');

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (isset($validated['risk_level'])) {
            $query->where('risk_level', $validated['risk_level']);
        }

        return AiPredictionListResource::collection(
            $query->paginate((int) ($validated['per_page'] ?? 20))->withQueryString(),
        );
    }

    public function show(Request $request, string $prediction): JsonResponse
    {
        $this->authorize('viewAny', AiPrediction::class);

        $found = AiPrediction::query()
            ->with(['pcUnit.room.floor.building', 'aiModel', 'failurePattern'])
            ->where('uuid', $prediction)
            ->firstOrFail();

        $this->authorize('view', $found);

        return (new AiPredictionDetailResource($found))->response();
    }

    public function confirm(Request $request, string $prediction): JsonResponse
    {
        return $this->decide($request, $prediction, fn (AiPrediction $found, User $actor) => $this->decisions->confirm($found, $actor));
    }

    public function dismiss(Request $request, string $prediction): JsonResponse
    {
        return $this->decide($request, $prediction, fn (AiPrediction $found, User $actor) => $this->decisions->dismiss($found, $actor));
    }

    /**
     * @param  callable(AiPrediction, User): AiPrediction  $apply
     */
    private function decide(Request $request, string $prediction, callable $apply): JsonResponse
    {
        $this->authorize('manage', AiPrediction::class);

        $found = AiPrediction::query()->where('uuid', $prediction)->firstOrFail();
        $this->authorize('manage', $found);

        /** @var User $actor */
        $actor = $request->user();

        $decided = $apply($found, $actor);
        $decided->load(['pcUnit.room.floor.building', 'aiModel', 'failurePattern']);

        return (new AiPredictionDetailResource($decided))->response();
    }
}
