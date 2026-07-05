<?php

declare(strict_types=1);

namespace App\Domains\Identity\Exports;

use App\Domains\Identity\Services\UserDirectoryQuery;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Streams the User directory to CSV/XLSX (SRS v1.2 FR-USER export). Backed by the
 * exact same {@see UserDirectoryQuery} builder the
 * administrator is viewing, so the export honours the current search / filters /
 * sort. Columns are chosen from a fixed allow-list ({@see COLUMNS}); nothing
 * sensitive (password hash, tokens) is ever exportable.
 *
 * @implements WithMapping<User>
 */
class UsersExport implements FromQuery, WithHeadings, WithMapping
{
    /** Exportable column => human heading. The allow-list — order is preserved. */
    public const COLUMNS = [
        'name' => 'Name',
        'email' => 'Email',
        'employee_number' => 'Employee Number',
        'role' => 'Role',
        'status' => 'Status',
        'contact_number' => 'Contact Number',
        'registration_source' => 'Registration Source',
        'last_login_at' => 'Last Login',
        'password_changed_at' => 'Last Password Change',
        'force_password_reset' => 'Password Reset Required',
        'created_at' => 'Registered At',
    ];

    /**
     * @param  Builder<User>  $query
     * @param  list<string>  $columns  ordered subset of COLUMNS keys
     */
    public function __construct(
        private readonly Builder $query,
        private readonly array $columns,
    ) {}

    /**
     * @return Builder<User>
     */
    public function query(): Builder
    {
        return $this->query;
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return array_map(fn (string $key): string => self::COLUMNS[$key], $this->columns);
    }

    /**
     * @param  User  $row
     * @return list<string>
     */
    public function map($row): array
    {
        return array_map(fn (string $key): string => $this->value($row, $key), $this->columns);
    }

    private function value(User $user, string $key): string
    {
        return match ($key) {
            'name' => $user->fullName(),
            'email' => (string) $user->email,
            'employee_number' => (string) ($user->employee_number ?? ''),
            'role' => (string) ($user->role->name ?? ''),
            'status' => $user->status->label(),
            'contact_number' => (string) ($user->contact_number ?? ''),
            'registration_source' => (string) ($user->registration_source ?? ''),
            'last_login_at' => $user->last_login_at?->toDateTimeString() ?? '',
            'password_changed_at' => $user->password_changed_at?->toDateTimeString() ?? '',
            'force_password_reset' => $user->force_password_reset ? 'Yes' : 'No',
            'created_at' => $user->created_at?->toDateTimeString() ?? '',
            default => '',
        };
    }
}
