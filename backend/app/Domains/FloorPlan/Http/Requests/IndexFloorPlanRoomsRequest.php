<?php

declare(strict_types=1);

namespace App\Domains\FloorPlan\Http\Requests;

use App\Enums\RoomType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filters for the Floorplan room overview (Phase 2.11).
 *
 * Authorization is the route's policy gate (`viewAny` on RoomLayout) and
 * `FloorPlanOverviewQuery` checking it again; this request only shapes input.
 */
class IndexFloorPlanRoomsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:120'],
            'building' => ['nullable', 'string', 'uuid'],
            'room_type' => ['nullable', 'string', Rule::in(RoomType::values())],
            // "set_up" = has an active layout; "not_set_up" = has none.
            'layout' => ['nullable', 'string', Rule::in(['set_up', 'not_set_up'])],
            // "name" (default), "pcs" (most machines first), "attention"
            // (most Needs Fix, then Pending Repair, first).
            'sort' => ['nullable', 'string', Rule::in(['name', 'pcs', 'attention'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
