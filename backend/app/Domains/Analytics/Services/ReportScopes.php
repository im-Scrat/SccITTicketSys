<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Services;

use App\Domains\Analytics\DTOs\ReportFilters;
use App\Enums\RoomType;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The report filters as SQL fragments for the non-ticket populations
 * (Phase 2.10 R4, SDD DD-94): equipment, repairs and replacements are scoped
 * by a *room id expression* rather than through the ticket directory.
 *
 * Room, building and room type mean the same as on the ticket figures —
 * archived rooms and buildings included, "offices" the office type only —
 * so a location chip narrows every section the same way. Which room is meant
 * differs by population and each caller names it: a ticket's stored room, a
 * piece of equipment's room now, a repair's target's room now.
 */
final class ReportScopes
{
    public static function location(Builder $query, string $roomIdExpression, ReportFilters $filters): Builder
    {
        $room = DB::raw($roomIdExpression);

        if ($filters->room !== null) {
            $query->whereIn($room, DB::table('rooms')->select('id')->where('uuid', $filters->room));
        } elseif ($filters->building !== null) {
            $query->whereIn($room, DB::table('rooms as lr')
                ->join('floors as lf', 'lf.id', '=', 'lr.floor_id')
                ->join('buildings as lb', 'lb.id', '=', 'lf.building_id')
                ->select('lr.id')
                ->where('lb.uuid', $filters->building));
        }

        if ($filters->roomType !== null) {
            $types = $filters->roomType === 'other'
                ? array_map(static fn (RoomType $t): string => $t->value, TicketOverviewQuery::OTHER_ROOM_TYPES)
                : [$filters->roomType];
            $query->whereIn($room, DB::table('rooms')->select('id')->whereIn('room_type', $types));
        }

        return $query;
    }

    /** Category and problem area on a query over `tickets as t`. */
    public static function ticketCategory(Builder $query, ReportFilters $filters, string $alias = 't'): Builder
    {
        if ($filters->categories !== []) {
            $query->whereIn("{$alias}.category_id", DB::table('ticket_categories')->select('id')->whereIn('slug', $filters->categories));
        }

        if ($filters->problemAreas !== []) {
            $query->whereIn("{$alias}.category_id", DB::table('ticket_categories')->select('id')->whereIn('problem_area', $filters->problemAreas));
        }

        return $query;
    }
}
