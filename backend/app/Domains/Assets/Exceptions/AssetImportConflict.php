<?php

declare(strict_types=1);

namespace App\Domains\Assets\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * The import changed underneath a request (Phase 2.10 R6): another
 * administrator's correction or decision, a newer mapping, or a save that
 * already started. Rendered as 409 with a stable `code` and, for a changed
 * plan, the plan as it is now — so the confirm screen can show what changed
 * instead of saving what nobody saw.
 */
final class AssetImportConflict extends RuntimeException
{
    /**
     * @param  array<string, mixed>|null  $plan
     */
    public function __construct(public readonly string $reason, string $message, public readonly ?array $plan = null)
    {
        parent::__construct($message);
    }

    /**
     * @param  array<string, mixed>|null  $plan
     */
    public static function planChanged(?array $plan = null): self
    {
        return new self('plan_changed', 'The rows changed since you looked at this plan. Check the updated plan and confirm again.', $plan);
    }

    public function render(): JsonResponse
    {
        return response()->json(array_filter([
            'message' => $this->getMessage(),
            'code' => $this->reason,
            'plan' => $this->plan,
        ], fn ($v): bool => $v !== null), 409);
    }
}
