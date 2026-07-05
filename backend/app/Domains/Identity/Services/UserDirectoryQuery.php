<?php

declare(strict_types=1);

namespace App\Domains\Identity\Services;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Server-side directory query for User Management (SRS v1.2 FR-USER directory):
 * search, filter, sort, and paginate — all in the database, never in PHP.
 *
 * Sort columns are resolved through a fixed allow-list ({@see SORTABLE}) so a
 * client-supplied `sort` value can never reach raw SQL (injection-safe). The
 * same builder backs both the paginated list and the exporter, so an export
 * honours the identical search/filter/sort the administrator is viewing.
 */
class UserDirectoryQuery
{
    /** Public sort key => physical column (or the `role` sentinel, joined below). */
    private const SORTABLE = [
        'name' => 'first_name',
        'email' => 'email',
        'status' => 'status',
        'role' => 'role',
        'created_at' => 'created_at',
        'last_login_at' => 'last_login_at',
    ];

    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 100;

    /**
     * Build the filtered/sorted query (no pagination). Reused by the exporter.
     *
     * @param  array<string, mixed>  $params
     * @return Builder<User>
     */
    public function builder(array $params): Builder
    {
        $query = User::query()->with('role');

        $this->applyTrashed($query, (string) ($params['trashed'] ?? 'without'));
        $this->applySearch($query, isset($params['search']) ? (string) $params['search'] : null);
        $this->applyStatus($query, isset($params['status']) ? (string) $params['status'] : null);
        $this->applyRole($query, isset($params['role']) ? (string) $params['role'] : null);
        $this->applySort(
            $query,
            (string) ($params['sort'] ?? 'created_at'),
            strtolower((string) ($params['direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc',
        );

        return $query;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return LengthAwarePaginator<int, User>
     */
    public function paginate(array $params): LengthAwarePaginator
    {
        $perPage = (int) ($params['per_page'] ?? self::DEFAULT_PER_PAGE);
        $perPage = max(1, min($perPage, self::MAX_PER_PAGE));

        return $this->builder($params)->paginate($perPage)->withQueryString();
    }

    /**
     * @param  Builder<User>  $query
     */
    private function applyTrashed(Builder $query, string $mode): void
    {
        match ($mode) {
            'only' => $query->onlyTrashed(),
            'with' => $query->withTrashed(),
            default => null, // 'without' — the default scope already excludes trashed.
        };
    }

    /**
     * Case-insensitive substring match across name, email and employee number.
     * User-supplied wildcards are escaped so `%`/`_` are treated literally.
     *
     * @param  Builder<User>  $query
     */
    private function applySearch(Builder $query, ?string $search): void
    {
        $search = $search !== null ? trim($search) : '';

        if ($search === '') {
            return;
        }

        $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search).'%';

        $query->where(function (Builder $q) use ($term): void {
            $q->where('first_name', 'ILIKE', $term)
                ->orWhere('last_name', 'ILIKE', $term)
                ->orWhere('email', 'ILIKE', $term)
                ->orWhere('employee_number', 'ILIKE', $term)
                ->orWhereRaw("(first_name || ' ' || last_name) ILIKE ?", [$term]);
        });
    }

    /**
     * @param  Builder<User>  $query
     */
    private function applyStatus(Builder $query, ?string $status): void
    {
        if ($status === null || $status === '' || $status === 'all') {
            return;
        }

        $query->where('status', $status);
    }

    /**
     * @param  Builder<User>  $query
     */
    private function applyRole(Builder $query, ?string $roleSlug): void
    {
        if ($roleSlug === null || $roleSlug === '' || $roleSlug === 'all') {
            return;
        }

        $query->whereHas('role', fn (Builder $q) => $q->where('slug', $roleSlug));
    }

    /**
     * @param  Builder<User>  $query
     * @param  'asc'|'desc'  $direction
     */
    private function applySort(Builder $query, string $sort, string $direction): void
    {
        $column = self::SORTABLE[$sort] ?? 'created_at';

        if ($column === 'role') {
            $query->leftJoin('roles', 'roles.id', '=', 'users.role_id')
                ->orderBy('roles.name', $direction)
                ->select('users.*');

            return;
        }

        if ($column === 'first_name') {
            $query->orderBy('first_name', $direction)->orderBy('last_name', $direction);

            return;
        }

        $query->orderBy($column, $direction);
    }
}
