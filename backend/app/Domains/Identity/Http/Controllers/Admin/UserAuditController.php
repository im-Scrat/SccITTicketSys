<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Controllers\Admin;

use App\Domains\Identity\Http\Resources\ActivityLogResource;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Per-user audit timeline (SRS FR-AUD-*). Returns activity-log entries where the
 * user is either the actor or the subject, newest first, paginated — the
 * complete chronological history the detail page renders (registration,
 * approval, login events, role/permission/status changes, admin actions).
 */
class UserAuditController extends Controller
{
    public function index(Request $request, User $user): AnonymousResourceCollection
    {
        $this->authorize('viewAudit', $user);

        $logs = ActivityLog::query()
            ->with('user:id,uuid,first_name,last_name')
            ->where(function (Builder $query) use ($user): void {
                $query->where('user_id', $user->getKey())
                    ->orWhere(function (Builder $inner) use ($user): void {
                        $inner->where('subject_type', $user->getMorphClass())
                            ->where('subject_id', $user->getKey());
                    });
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25);

        return ActivityLogResource::collection($logs);
    }
}
