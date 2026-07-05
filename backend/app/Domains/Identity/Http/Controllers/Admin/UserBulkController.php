<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Controllers\Admin;

use App\Domains\Identity\Actions\ChangeUserRole;
use App\Domains\Identity\Actions\ChangeUserStatus;
use App\Domains\Identity\Actions\RejectRegistration;
use App\Domains\Identity\Http\Requests\BulkUserActionRequest;
use App\Domains\Identity\Notifications\AdminMessage;
use App\Domains\Identity\Services\AuditLogger;
use App\Domains\Identity\Services\UserExporter;
use App\Enums\ActivityAction;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Enterprise bulk administration (SRS v1.2 FR-USER bulk operations). Every
 * mutating batch is transactional and all-or-nothing: the per-record policy is
 * re-checked for each selected user, so an operation that is not permitted on
 * any one target rolls the whole batch back. Each per-user change audits itself
 * via its Action; a BulkAction summary is also recorded. "Export selected"
 * streams a file instead of a JSON body.
 */
class UserBulkController extends Controller
{
    public function __construct(
        private readonly ChangeUserStatus $changeStatus,
        private readonly ChangeUserRole $changeRole,
        private readonly RejectRegistration $reject,
        private readonly UserExporter $exporter,
        private readonly AuditLogger $audit,
    ) {}

    public function store(BulkUserActionRequest $request): JsonResponse|BinaryFileResponse
    {
        $data = $request->validated();
        /** @var User $admin */
        $admin = $request->user();
        /** @var list<string> $ids */
        $ids = $data['ids'];
        $action = (string) $data['action'];

        if ($action === 'export') {
            /** @var list<string> $columns */
            $columns = $data['columns'] ?? [];

            return $this->exporter->downloadForIds($ids, ($data['format'] ?? 'xlsx') === 'csv' ? 'csv' : 'xlsx', $columns);
        }

        $users = User::query()->with('role')->whereIn('uuid', $ids)->get();

        $count = DB::transaction(function () use ($users, $action, $data, $admin, $request): int {
            $applied = 0;

            foreach ($users as $user) {
                $this->applyOne($action, $user, $data, $admin, $request);
                $applied++;
            }

            return $applied;
        });

        $this->audit->activity(
            ActivityAction::BulkAction,
            actor: $admin,
            properties: ['action' => $action, 'count' => $count, 'ids' => $ids],
            request: $request,
            description: "Bulk action: {$action}",
        );

        return response()->json([
            'message' => "Applied '{$action}' to {$count} user(s).",
            'count' => $count,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applyOne(string $action, User $user, array $data, User $admin, BulkUserActionRequest $request): void
    {
        $reason = isset($data['reason']) ? (string) $data['reason'] : null;

        match ($action) {
            'activate', 'reactivate' => $this->guarded('activate', $user, $admin,
                fn () => $this->changeStatus->handle($user, UserStatus::Active, $admin, $request)),
            'suspend' => $this->guarded('suspend', $user, $admin,
                fn () => $this->changeStatus->handle($user, UserStatus::Suspended, $admin, $request, $reason)),
            'deactivate' => $this->guarded('suspend', $user, $admin,
                fn () => $this->changeStatus->handle($user, UserStatus::Inactive, $admin, $request, $reason)),
            'reject' => $this->guarded('reject', $user, $admin,
                fn () => $this->reject->handle($user, $admin, $reason, $request)),
            'role' => $this->guarded('changeRole', $user, $admin,
                fn () => $this->changeRole->handle($user, (string) $data['role'], $admin, $request)),
            'notify' => $this->guarded('update', $user, $admin,
                fn () => $user->notify(new AdminMessage((string) $data['subject'], [(string) $data['message']]))),
            default => throw new AuthorizationException('Unsupported bulk action.'),
        };
    }

    /**
     * Re-check the per-record policy for the actor before running the change.
     */
    private function guarded(string $ability, User $user, User $admin, callable $run): void
    {
        if (! $admin->can($ability, $user)) {
            throw new AuthorizationException("Not permitted to {$ability} {$user->email}.");
        }

        $run();
    }
}
